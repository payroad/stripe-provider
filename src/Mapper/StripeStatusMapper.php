<?php

declare(strict_types=1);

namespace Payroad\Provider\Stripe\Mapper;

use Payroad\Domain\Attempt\AttemptStatus;
use Payroad\Domain\Refund\RefundStatus;

final class StripeStatusMapper
{
    /**
     * Maps a Stripe PaymentIntent event type to a domain AttemptStatus.
     * Returns null for events that carry no meaningful status transition
     * (e.g. payment_intent.created, payment_intent.requires_action).
     */
    public function mapPaymentIntentEvent(string $eventType): ?AttemptStatus
    {
        return match ($eventType) {
            'payment_intent.succeeded'                 => AttemptStatus::SUCCEEDED,
            'payment_intent.payment_failed'            => AttemptStatus::FAILED,
            'payment_intent.processing'                => AttemptStatus::PROCESSING,
            'payment_intent.canceled'                  => AttemptStatus::CANCELED,
            'payment_intent.amount_capturable_updated' => AttemptStatus::AUTHORIZED,
            default                                    => null,
        };
    }

    /**
     * Maps a Stripe Refund event type to a domain RefundStatus.
     * Returns null for events that carry no meaningful status transition.
     */
    public function mapRefundEvent(string $eventType): ?RefundStatus
    {
        return match ($eventType) {
            'refund.updated', 'charge.refunded' => RefundStatus::SUCCEEDED,
            'charge.refund.updated'             => RefundStatus::FAILED,
            default                             => null,
        };
    }
}
