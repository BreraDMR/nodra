<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures;

use App\DataFixtures\AppFixtures;
use PHPUnit\Framework\TestCase;

/**
 * Since P02 the demo admin password ships in no published material: the fixtures
 * take an explicit password, keep the well-known one only in dev, and fall back
 * to a generated random anywhere else.
 */
final class AppFixturesTest extends TestCase
{
    public function testAnExplicitPasswordAlwaysWins(): void
    {
        self::assertSame('s3cret!', AppFixtures::adminPassword('s3cret!', 'prod', 'random'));
        self::assertSame('s3cret!', AppFixtures::adminPassword('s3cret!', 'dev', 'random'));
    }

    public function testDevFallsBackToTheWellKnownPassword(): void
    {
        self::assertSame(AppFixtures::DEV_PASSWORD, AppFixtures::adminPassword('', 'dev', 'random'));
        self::assertSame(AppFixtures::DEV_PASSWORD, AppFixtures::adminPassword(null, 'dev', 'random'));
    }

    public function testAnyOtherEnvironmentTakesTheGeneratedRandom(): void
    {
        self::assertSame('random', AppFixtures::adminPassword('', 'prod', 'random'));
        self::assertSame('random', AppFixtures::adminPassword(null, 'test', 'random'));
        self::assertSame('random', AppFixtures::adminPassword('   ', 'demo', 'random'));
    }
}
