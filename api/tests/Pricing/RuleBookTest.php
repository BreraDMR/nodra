<?php

declare(strict_types=1);

namespace App\Tests\Pricing;

use App\Pricing\RuleBook;
use App\Pricing\RuleFacts;
use PHPUnit\Framework\TestCase;

final class RuleBookTest extends TestCase
{
    private RuleBook $book;

    protected function setUp(): void
    {
        // components > drivetrain > chains, bags has no rules of its own
        $parents = ['components' => null, 'drivetrain' => 'components', 'chains' => 'drivetrain', 'bags' => null];
        $this->book = new RuleBook([
            new RuleFacts('d1', null, 0, 30000, 6000),
            new RuleFacts('d2', null, 30000, 100000, 4000),
            new RuleFacts('d3', null, 100000, 300000, 3000),
            new RuleFacts('d4', null, 300000, null, 2000),
            new RuleFacts('drivetrain', 'drivetrain', 0, 100000, 3500),
            new RuleFacts('chains', 'chains', 50000, 80000, 2500),
            new RuleFacts('off', 'components', 0, null, 5000, active: false),
        ], $parents);
    }

    public function testNearestCategoryWithAMatchingBandWins(): void
    {
        self::assertSame('chains', $this->book->find('chains', 60000)?->id);
        // chains' band doesn't hold it, the parent's does
        self::assertSame('drivetrain', $this->book->find('chains', 40000)?->id);
        // max is exclusive
        self::assertSame('drivetrain', $this->book->find('chains', 80000)?->id);
        self::assertSame('chains', $this->book->find('chains', 50000)?->id);
    }

    public function testFallsBackToTheDefaultBandAndSkipsInactiveRules(): void
    {
        // no category band up the tree (components' rule is inactive)
        self::assertSame('d3', $this->book->find('chains', 150000)?->id);
        self::assertSame('d1', $this->book->find('bags', 29999)?->id);
        self::assertSame('d2', $this->book->find('bags', 30000)?->id);
        self::assertSame('d4', $this->book->find('bags', 5_000_000)?->id);
        self::assertSame('d2', $this->book->find('unknown-category', 50000)?->id);
    }

    public function testNoRuleWithoutDefaultsAndNoLoopOnABrokenTree(): void
    {
        $book = new RuleBook([new RuleFacts('a', 'a', 0, 100, 1000)], ['a' => 'b', 'b' => 'a']);

        self::assertNull($book->find('a', 500));
        self::assertSame('a', $book->find('b', 50)?->id);
    }
}
