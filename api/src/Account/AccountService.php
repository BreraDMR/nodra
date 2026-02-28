<?php

declare(strict_types=1);

namespace App\Account;

use App\Entity\CustomerAccount;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Uid\Uuid;
use Psr\Log\LoggerInterface;

final class AccountService
{
    public function __construct(private EntityManagerInterface $em, private Connection $db, private MailerInterface $mailer, private LoggerInterface $logger) {}

    public function current(Request $request): ?CustomerAccount
    {
        $id = $request->getSession()->get('customer_account_id');

        return is_string($id) && Uuid::isValid($id) ? $this->em->find(CustomerAccount::class, Uuid::fromString($id)) : null;
    }

    public function register(Request $request, string $sub, string $email, string $name, string $locale = 'en'): CustomerAccount
    {
        $account = $this->em->getRepository(CustomerAccount::class)->findOneBy(['googleSub' => $sub]);
        $created = $account === null;
        if ($created) {
            $account = new CustomerAccount($sub, $email, $name);
            $this->em->persist($account);
        } else {
            $account->updateProfile($email, $name);
        }
        $this->em->flush();
        $request->getSession()->migrate(true);
        $request->getSession()->set('customer_account_id', $account->getId()->toRfc4122());

        if ($created) {
            $messages = [
                'cs' => ['Vítejte v NODRA', "Vítejte v NODRA, {$name}. Váš účet je připraven. Za každých 100 Kč hodnoty zboží získáte po dokončení ukázkové objednávky jeden bod. Platba ani zásilka neproběhne."],
                'de' => ['Willkommen bei NODRA', "Willkommen bei NODRA, {$name}. Dein Konto ist bereit. Nach Abschluss einer Demo-Bestellung erhältst du einen Punkt pro 4 € Warenwert. Es erfolgt weder Zahlung noch Versand."],
                'en' => ['Welcome to NODRA', "Welcome to NODRA, {$name}. Your account is ready. Earn one point per 4 € of products after a completed demo order. No payment or shipment takes place."],
            ];
            [$subject, $body] = $messages[$locale] ?? $messages['en'];
            try {
                $this->mailer->send((new Email())
                    ->from('hello@nodra.test')
                    ->to($email)
                    ->subject($subject)
                    ->text($body));
            } catch (TransportExceptionInterface $error) {
                $this->logger->error('Customer welcome email failed', ['exception' => $error]);
            }
        }

        return $account;
    }

    public function summary(CustomerAccount $account): array
    {
        $id = $account->getId()->toRfc4122();
        $points = (int) $this->db->fetchOne('SELECT COALESCE(SUM(points), 0) FROM loyalty_entry WHERE account_id = :id', ['id' => $id]);
        $history = $this->db->fetchAllAssociative('SELECT e.points, o.reference, e.created_at FROM loyalty_entry e JOIN shop_order o ON o.id = e.shop_order_id WHERE e.account_id = :id ORDER BY e.created_at DESC', ['id' => $id]);

        return [
            'name' => $account->getDisplayName(),
            'email' => $account->getEmail(),
            'points' => $points,
            'history' => array_map(static fn (array $row): array => ['reference' => $row['reference'], 'points' => (int) $row['points'], 'createdAt' => $row['created_at']], $history),
        ];
    }
}
