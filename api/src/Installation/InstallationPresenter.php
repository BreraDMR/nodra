<?php

declare(strict_types=1);

namespace App\Installation;

use App\Entity\InstallationBooking;

/** Shapes a booking for the admin responses; the current settings name the works. */
final class InstallationPresenter
{
    public static function present(InstallationBooking $booking, InstallationSettings $settings): array
    {
        $workNames = array_map(static fn (string $code): string => $settings->work($code)['name'] ?? $code, $booking->getWorks());
        $windowFrom = $booking->windowFrom();
        $duration = max(0, $booking->windowTo()->getTimestamp() - $windowFrom->getTimestamp());

        return [
            'id' => $booking->getId()->toRfc4122(),
            'orderId' => $booking->getOrder()->getId()->toRfc4122(),
            'orderReference' => $booking->getOrder()->getReference(),
            'customerName' => $booking->getOrder()->getCustomerName(),
            'status' => $booking->getStatus(),
            'from' => $booking->getPlannedFrom()->format(\DATE_ATOM),
            'to' => $booking->getPlannedTo()->format(\DATE_ATOM),
            'workFrom' => $booking->getWorkFrom()?->format(\DATE_ATOM),
            'workTo' => $booking->getWorkTo()?->format(\DATE_ATOM),
            'durationMinutes' => (int) round($duration / 60),
            'works' => $booking->getWorks(),
            'workNames' => $workNames,
            'priceMinor' => $booking->getPriceMinor(),
            'note' => $booking->getNote(),
            'compatibilityNote' => $booking->getCompatibilityNote(),
            'resultNote' => $booking->getResultNote(),
            'cancelledReason' => $booking->getCancelledReason(),
            'createdAt' => $booking->getCreatedAt()->format(\DATE_ATOM),
            'createdBy' => $booking->getCreatedBy(),
            'confirmedAt' => $booking->getConfirmedAt()?->format(\DATE_ATOM),
            'completedAt' => $booking->getCompletedAt()?->format(\DATE_ATOM),
            'cancelledAt' => $booking->getCancelledAt()?->format(\DATE_ATOM),
        ];
    }
}
