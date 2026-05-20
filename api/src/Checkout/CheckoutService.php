<?php

declare(strict_types=1);

namespace App\Checkout;

use App\Delivery\DeliveryOption;
use App\Delivery\DeliveryRules;
use App\Entity\CustomerAccount;
use App\Entity\OrderEvent;
use App\Entity\OrderItem;
use App\Entity\ProductVariant;
use App\Entity\Shipment;
use App\Entity\ShopOrder;
use App\Entity\SupplierOffer;
use App\Order\OrderJournal;
use App\Order\OrderLoader;
use App\Order\OrderPresenter;
use App\Order\OrderProblem;
use App\Order\StockKeeper;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * Order request checkout. Nothing is paid here: NODRA confirms the request with the customer, buys what isn't in
 * own stock, and takes the money on receipt.
 */
final class CheckoutService
{
    public function __construct(
        private EntityManagerInterface $em,
        private Connection $db,
        private BasketLoader $basket,
        private QuoteBuilder $quotes,
        private DeliveryRules $delivery,
        private OrderJournal $journal,
        private StockKeeper $stock,
        private OrderLoader $orders,
        private OrderPresenter $presenter,
        #[Autowire(param: 'app.legal.privacy_version')] private string $privacyVersion,
    ) {}

    /** No writes, no locks. */
    public function quote(QuoteRequest $request): array
    {
        $method = $request->delivery['method'];
        if (!in_array($method, $this->delivery->offered(), true)) {
            throw OrderProblem::unprocessable('method_unavailable', 'This delivery method is not offered');
        }
        $lines = $this->basket->lines(self::quantities($request->items), $request->locale);

        return $this->quotes->build($lines, $method, $request->delivery['postalCode'] ?? null)->toArray();
    }

    public function place(CheckoutRequest $request, string $key, ?CustomerAccount $account = null): array
    {
        if (strlen($key) < 16 || strlen($key) > 80) {
            throw OrderProblem::unprocessable('invalid_idempotency_key', 'Idempotency-Key must contain 16 to 80 characters');
        }
        if ($request->promotionCode !== null) {
            throw OrderProblem::invalid('promotionCode', 'Promotion codes are not available yet');
        }
        $method = $request->delivery['method'];
        $fulfilment = $request->delivery['fulfilment'];
        $customer = CustomerInput::normalize($request->customer, $method);
        if ($account !== null && strcasecmp($customer['email'], $account->getEmail()) !== 0) {
            throw OrderProblem::invalid('customer.email', 'Use your account email for loyalty points');
        }
        if (($request->consents['privacy'] ?? null) !== true) {
            throw OrderProblem::invalid('consents.privacy', 'Consent to the processing of personal data is required');
        }
        $marketing = ($request->consents['marketing'] ?? false) === true;
        $quantities = self::quantities($request->items);
        // expectedTotal stays out: repeating a placed order with another expectation still returns that order
        $requestHash = hash('sha256', json_encode([
            $request->locale, $customer, $quantities, [$method, $fulfilment], [true, $marketing], $account?->getId()->toRfc4122(),
        ], JSON_THROW_ON_ERROR));

        try {
            $order = $this->db->transactional(function () use ($request, $key, $requestHash, $customer, $quantities, $method, $fulfilment, $marketing, $account): ShopOrder {
                $this->db->fetchOne('SELECT pg_advisory_xact_lock(hashtextextended(:key, 0))', ['key' => $key]);
                $existing = $this->em->getRepository(ShopOrder::class)->findOneBy(['idempotencyKey' => $key]);
                if ($existing !== null) {
                    return $this->sameRequest($existing, $requestHash);
                }

                $quote = $this->quotes->build($this->basket->lines($quantities, $request->locale, lock: true), $method, $customer['postalCode']);
                $option = $this->chosenOption($quote, $fulfilment);
                if ($quote->subtotalMinor + $option->shippingMinor() !== $request->expectedTotal) {
                    throw OrderProblem::conflict('quote_changed', 'Prices, availability or delivery changed; check the new total and send the order again', ['quote' => $quote->toArray()]);
                }

                return $this->create($quote, $option, $key, $requestHash, $request->locale, $customer, $marketing, $account);
            });
        } catch (UniqueConstraintViolationException) {
            // a parallel request with the same key won the insert
            $this->em->clear();
            $order = $this->em->getRepository(ShopOrder::class)->findOneBy(['idempotencyKey' => $key])
                ?? throw new \DomainException('The order could not be completed');
            $this->sameRequest($order, $requestHash);
        }

        return $this->presenter->receipt($this->orders->state($order));
    }

    public function lookup(string $reference, string $token): ?array
    {
        $order = $this->em->getRepository(ShopOrder::class)->findOneBy(['reference' => $reference]);
        if ($order === null || !hash_equals($order->getLookupToken(), $token)) {
            return null;
        }

        return $this->presenter->receipt($this->orders->state($order));
    }

    private function chosenOption(Quote $quote, string $fulfilment): DeliveryOption
    {
        $unavailable = $quote->unavailableLine();
        if ($unavailable !== null) {
            throw OrderProblem::unprocessable('unavailable', sprintf('%s (%s) cannot be ordered at the moment: no supplier has it available', $unavailable->name, $unavailable->label), ['variantId' => $unavailable->variantId]);
        }
        if ($quote->methodProblem !== null) {
            throw OrderProblem::unprocessable('method_unavailable', match ($quote->methodProblem) {
                DeliveryRules::OUTSIDE_PRAGUE => 'Personal delivery covers Prague postal codes 100 00 to 199 99 only',
                DeliveryRules::NOT_OFFERED => 'This delivery method is not offered',
                default => 'This delivery method needs a Czech postal code',
            }, ['reason' => $quote->methodProblem]);
        }

        return $quote->option($fulfilment)
            ?? throw OrderProblem::unprocessable('split_unavailable', 'Every item arrives at the same time, so the order can only be delivered together');
    }

    private function create(Quote $quote, DeliveryOption $option, string $key, string $requestHash, string $locale, array $customer, bool $marketing, ?CustomerAccount $account): ShopOrder
    {
        $order = new ShopOrder($key, $requestHash, $locale, $customer, $option->fulfilment, $this->privacyVersion, $marketing);
        if ($account !== null) {
            $order->assignAccount($account);
        }
        $order->setTotals($quote->subtotalMinor, $option->shippingMinor());
        $this->em->persist($order);

        $lines = [];
        foreach ($quote->lines as $line) {
            $lines[$line->variantId] = $line;
        }
        foreach ($option->shipments as $planned) {
            $shipment = new Shipment($order, $planned->number, $quote->method, $planned->feeMinor);
            $this->em->persist($shipment);
            foreach ($planned->keys as $variantId) {
                $line = $lines[$variantId];
                $variant = $this->em->find(ProductVariant::class, Uuid::fromString($variantId));
                $item = new OrderItem($order, $shipment, $variant, $line->name, $line->label, $line->quantity, $line->unitPriceMinor, $line->fromStock);
                $source = $line->sourcing;
                $offer = $source->offer === null ? null : $this->em->getReference(SupplierOffer::class, Uuid::fromString($source->offer->id));
                $item->recordSourcing($source->status, $source->leadTimeMinDays, $source->leadTimeMaxDays, $offer, $source->landedCostCzk);
                $this->em->persist($item);
                // only goods NODRA holds are reserved; the rest is bought after confirmation
                if ($line->fromStock && !$this->stock->take($variant->getId(), $line->quantity, $order, 'reserved at checkout')) {
                    throw new \DomainException('Own stock changed during checkout, try again');
                }
            }
        }
        $this->journal->record($order, 'placed', [
            'method' => $quote->method, 'fulfilment' => $option->fulfilment, 'shipments' => count($option->shipments),
            'totalMinor' => $order->getTotalMinor(),
        ], OrderEvent::CUSTOMER);
        $this->em->flush();

        return $order;
    }

    private function sameRequest(ShopOrder $order, string $requestHash): ShopOrder
    {
        if (!hash_equals($order->getRequestHash(), $requestHash)) {
            throw OrderProblem::conflict('idempotency_conflict', 'Idempotency key was used with different order details');
        }

        return $order;
    }

    /** @return array<string, int> variant id => quantity, sorted by id */
    private static function quantities(array $items): array
    {
        $quantities = [];
        foreach ($items as $index => $item) {
            if (!is_array($item) || !isset($item['variantId'], $item['quantity']) || !is_string($item['variantId']) || !Uuid::isValid($item['variantId'])
                || !is_int($item['quantity']) || $item['quantity'] < 1 || $item['quantity'] > 10) {
                throw OrderProblem::invalid('items['.$index.']', 'Each item needs a valid variant and quantity from 1 to 10');
            }
            $id = strtolower($item['variantId']);
            $quantities[$id] = ($quantities[$id] ?? 0) + $item['quantity'];
            if ($quantities[$id] > 10) {
                throw OrderProblem::invalid('items['.$index.']', 'Only 10 units of one variant are allowed per order');
            }
        }
        ksort($quantities);

        return $quantities;
    }
}
