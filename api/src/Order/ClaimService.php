<?php

declare(strict_types=1);

namespace App\Order;

use App\Admin\AdminClaimsQuery;
use App\Entity\OrderItem;
use App\Entity\ReturnClaim;
use App\Entity\Shipment;
use App\Entity\ShopOrder;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The after-sale claims registry (D08.4). A claim is only a record beside the order: opening and moving it never
 * touches line states, stock or the ledger — those stay with the order actions. The one money rule: a claim closes
 * as `refund` only when the order ledger holds refunds tagged with this claim for the agreed amount — one refund
 * settles one claim, so the money of two cases can never mix.
 */
final class ClaimService
{
    public function __construct(
        private EntityManagerInterface $em,
        private Connection $db,
        private OrderJournal $journal,
        private ClockInterface $clock,
    ) {}

    public function open(string $orderId, ?string $itemId, string $kind, ?string $note, string $contactedOn, string $actor): array
    {
        $order = $this->em->find(ShopOrder::class, Uuid::fromString(strtolower($orderId)));
        if ($order === null) {
            throw OrderProblem::notFound('Order not found');
        }
        $item = null;
        if ($itemId !== null) {
            $item = $this->em->find(OrderItem::class, Uuid::fromString(strtolower($itemId)));
            if ($item === null || !$item->getOrder()->getId()->equals($order->getId())) {
                throw OrderProblem::notFound('Order line not found');
            }
        }
        $handover = $item === null
            ? $this->lastHandoverOf($order)
            : $item->getShipment()->getHandedOverAt();
        if ($handover === null) {
            throw OrderProblem::unprocessable('not_handed_over', 'A claim needs the goods handed over first');
        }
        if ($item !== null && $this->openClaimOnItem($item) !== null) {
            throw OrderProblem::conflict('claim_open', 'This line already has a claim that is not closed');
        }
        // the calendar day is read as a Prague date straight away, like the handover date is
        $contacted = \DateTimeImmutable::createFromFormat('!Y-m-d', $contactedOn, new \DateTimeZone(OrderQueues::TIMEZONE));
        if ($contacted === false) {
            throw OrderProblem::unprocessable('invalid_contact_date', 'The contact day must be a YYYY-MM-DD date');
        }
        if ($contacted->format('Y-m-d') > $this->today()->format('Y-m-d')) {
            throw OrderProblem::unprocessable('contact_in_future', 'The customer cannot have contacted in the future');
        }

        $handoverDate = $handover->setTimezone(new \DateTimeZone(OrderQueues::TIMEZONE))->setTime(0, 0);
        $claim = new ReturnClaim($order, $item, $kind, $note, $actor, $contacted, $handoverDate, ReturnClaim::windowEnd($kind, $handoverDate));
        $this->em->persist($claim);
        $this->journal->record($order, 'claim_opened', [
            'claimId' => $claim->getId()->toRfc4122(), 'number' => $claim->getNumber(), 'kind' => $kind,
            'itemId' => $item?->getId()->toRfc4122(), 'contactedOn' => $claim->getContactedOn()->format('Y-m-d'),
            'windowEnd' => $claim->getWindowEnd()->format('Y-m-d'), 'dueAt' => $claim->getDueAt()?->format('Y-m-d'),
        ], $actor);
        $this->em->flush();

        return ClaimPresenter::present($claim, $this->today());
    }

    public function show(string $claimId): array
    {
        return ClaimPresenter::present($this->claim($claimId), $this->today());
    }

    public function wait(string $claimId, ?string $note, string $actor): array
    {
        $claim = $this->claim($claimId);
        $claim->wait();
        $this->journal->record($claim->getOrder(), 'claim_waiting', ['claimId' => $claim->getId()->toRfc4122(), 'number' => $claim->getNumber(), 'note' => $note], $actor);
        $this->em->flush();

        return ClaimPresenter::present($claim, $this->today());
    }

    public function accept(string $claimId, ?int $refundAmountMinor, ?string $note, string $actor): array
    {
        $claim = $this->claim($claimId);
        $claim->accept($refundAmountMinor);
        $this->journal->record($claim->getOrder(), 'claim_accepted', [
            'claimId' => $claim->getId()->toRfc4122(), 'number' => $claim->getNumber(),
            'refundAmountMinor' => $refundAmountMinor, 'dueAt' => $claim->getDueAt()?->format('Y-m-d'), 'note' => $note,
        ], $actor);
        $this->em->flush();

        return ClaimPresenter::present($claim, $this->today());
    }

    public function reject(string $claimId, string $reason, string $actor): array
    {
        $claim = $this->claim($claimId);
        $claim->reject($reason);
        $this->journal->record($claim->getOrder(), 'claim_rejected', ['claimId' => $claim->getId()->toRfc4122(), 'number' => $claim->getNumber(), 'reason' => $reason], $actor);
        $this->em->flush();

        return ClaimPresenter::present($claim, $this->today());
    }

    public function resolve(string $claimId, string $resolution, ?string $note, string $actor): array
    {
        return $this->db->transactional(function () use ($claimId, $resolution, $note, $actor): array {
            $claim = $this->claim($claimId);
            if ($resolution === ReturnClaim::RESOLUTION_REFUND) {
                // the order row lock puts this check and the status flip into one step with the payment
                // recording and voiding, so two simultaneous actions can never share one refund
                $this->db->executeStatement('SELECT id FROM shop_order WHERE id = :id FOR UPDATE', ['id' => $claim->getOrder()->getId()->toRfc4122()]);
                $this->em->refresh($claim);
                $this->requireRefundRecorded($claim);
            }
            $claim->resolve($resolution);
            $this->journal->record($claim->getOrder(), 'claim_resolved', [
                'claimId' => $claim->getId()->toRfc4122(), 'number' => $claim->getNumber(), 'resolution' => $resolution, 'note' => $note,
            ], $actor);
            $this->em->flush();

            return ClaimPresenter::present($claim, $this->today());
        });
    }

    public function list(AdminClaimsQuery $query): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('claim', 'item')
            ->from(ReturnClaim::class, 'claim')
            ->leftJoin('claim.item', 'item')
            ->join('claim.order', 'o')
            ->orderBy('claim.openedAt', 'DESC')
            ->addOrderBy('claim.id', 'DESC');
        if ($query->status !== null) {
            $qb->andWhere('claim.status = :status')->setParameter('status', $query->status);
        }
        if ($query->kind !== null) {
            $qb->andWhere('claim.kind = :kind')->setParameter('kind', $query->kind);
        }
        $search = trim((string) $query->q);
        if ($search !== '') {
            $qb->andWhere('LOWER(claim.number) LIKE LOWER(:search) OR LOWER(o.reference) LIKE LOWER(:search) OR LOWER(o.customerName) LIKE LOWER(:search) OR LOWER(o.email) LIKE LOWER(:search)')
                ->setParameter('search', '%'.strtr($search, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']).'%');
        }
        $total = (int) (clone $qb)->select('COUNT(claim.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        $pages = max(1, (int) ceil($total / 30));
        $page = min($query->page, $pages);
        $claims = $qb->select('claim', 'item')
            ->setMaxResults(30)
            ->setFirstResult(($page - 1) * 30)
            ->getQuery()->getResult();
        $today = $this->today();

        return [
            'items' => array_map(static fn (ReturnClaim $claim): array => ClaimPresenter::present($claim, $today), $claims),
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
        ];
    }

    /** The claim closes as a refund only when the ledger holds it: refunds tagged with this claim, minus the ones voided since. */
    private function requireRefundRecorded(ReturnClaim $claim): void
    {
        $agreed = $claim->getRefundAmountMinor();
        $params = [
            'order' => $claim->getOrder()->getId()->toRfc4122(),
            'claim' => $claim->getId()->toRfc4122(),
        ];
        $refunded = (int) $this->db->fetchOne(
            "SELECT COALESCE(SUM(amount_minor), 0) FROM payment WHERE order_id = :order AND kind = 'refund' AND claim_id = :claim",
            $params,
        );
        $voided = (int) $this->db->fetchOne(
            "SELECT COALESCE(SUM(p.amount_minor), 0) FROM payment p JOIN payment r ON r.id = p.corrects_id
             WHERE p.order_id = :order AND p.kind = 'correction' AND r.claim_id = :claim",
            $params,
        );
        if ($agreed === null || $refunded - $voided < $agreed) {
            throw OrderProblem::conflict('refund_not_recorded', 'Record the refund on the order first, tagged with this claim; nothing covers the agreed amount yet', [
                'agreedMinor' => $agreed, 'coveredMinor' => max(0, $refunded - $voided),
            ]);
        }
    }

    private function claim(string $claimId): ReturnClaim
    {
        try {
            $uuid = Uuid::fromString(strtolower($claimId));
        } catch (\InvalidArgumentException) {
            throw OrderProblem::notFound('Claim not found');
        }
        $claim = $this->em->find(ReturnClaim::class, $uuid);
        if ($claim === null) {
            throw OrderProblem::notFound('Claim not found');
        }

        return $claim;
    }

    private function lastHandoverOf(ShopOrder $order): ?\DateTimeImmutable
    {
        $times = [];
        foreach ($this->em->getRepository(Shipment::class)->findBy(['order' => $order]) as $shipment) {
            if ($shipment->getHandedOverAt() !== null) {
                $times[] = $shipment->getHandedOverAt();
            }
        }

        return $times === [] ? null : max($times);
    }

    private function openClaimOnItem(OrderItem $item): ?ReturnClaim
    {
        return $this->em->createQueryBuilder()
            ->select('claim')
            ->from(ReturnClaim::class, 'claim')
            ->where('claim.item = :item')
            ->andWhere('claim.status IN (:statuses)')
            ->setParameter('item', $item->getId())
            ->setParameter('statuses', ReturnClaim::OPEN_STATUSES)
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }

    private function today(): \DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone(OrderQueues::TIMEZONE));
    }
}
