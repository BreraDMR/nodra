<?php

declare(strict_types=1);

namespace App\Order;

use App\Entity\ReturnClaim;

/** Shapes a claim for the admin responses; $today is the Prague "now" of the caller. */
final class ClaimPresenter
{
    public static function present(ReturnClaim $claim, \DateTimeImmutable $today): array
    {
        $item = $claim->getItem();

        return [
            'id' => $claim->getId()->toRfc4122(),
            'number' => $claim->getNumber(),
            'orderId' => $claim->getOrder()->getId()->toRfc4122(),
            'orderReference' => $claim->getOrder()->getReference(),
            'customerName' => $claim->getOrder()->getCustomerName(),
            'itemId' => $item?->getId()->toRfc4122(),
            'productName' => $item?->getProductName(),
            'variantLabel' => $item?->getVariantLabel(),
            'sku' => $item?->getSku(),
            'kind' => $claim->getKind(),
            'status' => $claim->getStatus(),
            'note' => $claim->getNote(),
            'refundAmountMinor' => $claim->getRefundAmountMinor(),
            'resolution' => $claim->getResolution(),
            'resolutionNote' => $claim->getResolutionNote(),
            'openedAt' => $claim->getOpenedAt()->format(\DATE_ATOM),
            'openedBy' => $claim->getOpenedBy(),
            'contactedOn' => $claim->getContactedOn()->format('Y-m-d'),
            'handoverDate' => $claim->getHandoverDate()->format('Y-m-d'),
            'windowEnd' => $claim->getWindowEnd()->format('Y-m-d'),
            'onTime' => $claim->isOnTime(),
            'dueAt' => $claim->getDueAt()?->format('Y-m-d'),
            'overdue' => $claim->isOverdue($today),
            'resolvedAt' => $claim->getResolvedAt()?->format(\DATE_ATOM),
        ];
    }
}
