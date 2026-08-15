<?php

declare(strict_types=1);

namespace App\Installation;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The app.installation.* parameters from config/services.yaml. The works list ships empty: the owner fills it
 * (D00.4), and until then no code and no price exists and the customer sees no way to buy an installation.
 */
final readonly class InstallationSettings
{
    public const TIMEZONE = 'Europe/Prague';

    /**
     * @param list<array{code: string, name: string, priceMinor: int|null}> $works
     */
    public function __construct(
        #[Autowire(param: 'app.installation.evening_start')] public int $eveningStart,
        #[Autowire(param: 'app.installation.evening_end')] public int $eveningEnd,
        #[Autowire(param: 'app.installation.max_per_evening')] public int $maxPerEvening,
        #[Autowire(param: 'app.installation.travel_minutes')] public int $travelMinutes,
        #[Autowire(param: 'app.installation.works')] public array $works,
    ) {
        if ($eveningStart < 0 || $eveningEnd > 24 || $eveningStart >= $eveningEnd) {
            throw new \InvalidArgumentException('app.installation evening hours are not a real window');
        }
        if ($maxPerEvening < 1 || $travelMinutes < 0) {
            throw new \InvalidArgumentException('app.installation limit and travel must be sane');
        }
        foreach ($works as $work) {
            if (!is_string($work['code'] ?? null) || $work['code'] === '' || !is_string($work['name'] ?? null)) {
                throw new \InvalidArgumentException('app.installation.works needs code and name for every work');
            }
        }
    }

    public function allowsCustomerPurchase(): bool
    {
        return $this->works !== [];
    }

    /** The work as the setting names it, or null for an unknown code. */
    public function work(string $code): ?array
    {
        foreach ($this->works as $work) {
            if ($work['code'] === $code) {
                return $work;
            }
        }

        return null;
    }

    /**
     * The price of the chosen works: null while nothing is chosen or any chosen work has no price yet, the sum otherwise.
     *
     * @param list<string> $codes
     */
    public function priceMinor(array $codes): ?int
    {
        if ($codes === []) {
            return null;
        }
        $sum = 0;
        foreach ($codes as $code) {
            $price = $this->work($code)['priceMinor'] ?? false;
            if ($price === false || $price === null) {
                return null;
            }
            $sum += (int) $price;
        }

        return $sum;
    }
}
