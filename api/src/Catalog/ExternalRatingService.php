<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Admin\ExternalRatingWriteRequest;
use App\Entity\ExternalRating;
use App\Entity\Product;
use App\Order\OrderProblem;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * D07.3: the outside ratings of a product, typed in by hand — never scraped, never invented.
 * One row per source and model; re-checking updates the row and moves the checked day.
 */
final class ExternalRatingService
{
    public function __construct(private EntityManagerInterface $em) {}

    /** @return list<array<string, mixed>> */
    public function allOf(Product $product): array
    {
        $ratings = $this->em->getRepository(ExternalRating::class)->findBy(
            ['product' => $product],
            ['checkedAt' => 'DESC', 'id' => 'DESC'],
        );

        return ['items' => array_map(self::present(...), $ratings), 'total' => count($ratings)];
    }

    public function create(Product $product, ExternalRatingWriteRequest $payload): array
    {
        $model = $this->model($payload->model);
        $existing = $this->em->getRepository(ExternalRating::class)->findOneBy([
            'product' => $product, 'source' => $payload->source, 'model' => $model,
        ]);
        if ($existing !== null) {
            throw OrderProblem::conflict('rating_exists', sprintf('This source already holds a rating for model %s', $model));
        }
        $rating = new ExternalRating(
            $product, $payload->source, $model, self::decimal($payload->rating), self::decimal($payload->ratingScale ?? 5.0),
            $payload->ratingCount, new \DateTimeImmutable($payload->checkedAt), $payload->url,
        );
        $this->em->persist($rating);
        $this->em->flush();

        return self::present($rating);
    }

    public function update(string $id, ExternalRatingWriteRequest $payload): array
    {
        $rating = $this->rating($id);
        $rating->update(
            self::decimal($payload->rating), self::decimal($payload->ratingScale ?? 5.0), $payload->ratingCount,
            new \DateTimeImmutable($payload->checkedAt), $payload->url,
        );
        $this->em->flush();

        return self::present($rating);
    }

    public function delete(string $id): void
    {
        $this->em->remove($this->rating($id));
        $this->em->flush();
    }

    private function rating(string $id): ExternalRating
    {
        try {
            $uuid = Uuid::fromString(strtolower($id));
        } catch (\InvalidArgumentException) {
            throw OrderProblem::notFound('Rating not found');
        }
        $rating = $this->em->find(ExternalRating::class, $uuid);
        if ($rating === null) {
            throw OrderProblem::notFound('Rating not found');
        }

        return $rating;
    }

    /** Doctrine decimals are strings; the payload floats are normalised to the column scale. */
    private static function decimal(float $value): string
    {
        return number_format($value, 3, '.', '');
    }

    private function model(string $model): string
    {
        return trim($model);
    }

    /** @return array<string, mixed> */
    public static function present(ExternalRating $rating): array
    {
        return [
            'id' => $rating->getId()->toRfc4122(),
            'productId' => $rating->getProduct()->getId()->toRfc4122(),
            'source' => $rating->getSource(),
            'model' => $rating->getModel(),
            'rating' => (float) $rating->getRating(),
            'ratingScale' => (float) $rating->getRatingScale(),
            'ratingCount' => $rating->getRatingCount(),
            'checkedAt' => $rating->getCheckedAt()->format('Y-m-d'),
            'url' => $rating->getUrl(),
            'updatedAt' => $rating->getUpdatedAt()->format(\DATE_ATOM),
        ];
    }
}
