<?php

declare(strict_types=1);

namespace Payroad\Provider\Stripe\Data;

use Payroad\Port\Provider\Card\CardRefundData;

final readonly class StripeCardRefundData implements CardRefundData
{
    public function __construct(
        private ?string $reason                  = null,
        private ?string $acquirerReferenceNumber = null,
    ) {}

    public function getReason(): ?string                  { return $this->reason; }
    public function getAcquirerReferenceNumber(): ?string { return $this->acquirerReferenceNumber; }

    public function toArray(): array
    {
        return [
            'reason'                  => $this->reason,
            'acquirerReferenceNumber' => $this->acquirerReferenceNumber,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            reason:                  $data['reason']                  ?? null,
            acquirerReferenceNumber: $data['acquirerReferenceNumber'] ?? null,
        );
    }
}
