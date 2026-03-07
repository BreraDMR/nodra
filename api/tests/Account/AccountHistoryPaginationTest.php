<?php

declare(strict_types=1);

namespace App\Tests\Account;

use App\Account\AccountService;
use App\Entity\CustomerAccount;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;

final class AccountHistoryPaginationTest extends TestCase
{
    public function testSummaryBoundsHistoryWithoutChangingThePointsTotal(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetchAssociative')->willReturn(['points' => '91', 'entries' => '47']);
        $db->expects(self::once())->method('fetchAllAssociative')->willReturnCallback(
            static function (string $sql, array $params): array {
                self::assertSame(20, $params['limit']);
                self::assertSame(20, $params['offset']);

                return [['points' => '2', 'reference' => 'ND-DEMO', 'created_at' => '2026-09-28']];
            },
        );
        $service = new AccountService(
            $this->createStub(EntityManagerInterface::class),
            $db,
            $this->createStub(MailerInterface::class),
            $this->createStub(LoggerInterface::class),
        );

        $summary = $service->summary(new CustomerAccount('demo', 'rider@example.test', 'Rider'), 2);

        self::assertSame(91, $summary['points']);
        self::assertSame(47, $summary['historyTotal']);
        self::assertSame(2, $summary['historyPage']);
        self::assertSame(3, $summary['historyPages']);
        self::assertCount(1, $summary['history']);
    }
}
