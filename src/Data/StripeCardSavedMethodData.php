<?php

declare(strict_types=1);

namespace Payroad\Provider\Stripe\Data;

use Payroad\Domain\PaymentFlow\Card\CardSavedPaymentMethodData;

final readonly class StripeCardSavedMethodData implements CardSavedPaymentMethodData
{
    public function __construct(
        private string  $last4,
        private int     $expiryMonth,
        private int     $expiryYear,
        private string  $cardBrand,
        private string  $fundingType,
        private ?string $cardholderName  = null,
        private ?string $issuingCountry  = null,
    ) {}

    public function getLast4(): string           { return $this->last4; }
    public function getExpiryMonth(): int        { return $this->expiryMonth; }
    public function getExpiryYear(): int         { return $this->expiryYear; }
    public function getCardholderName(): ?string { return $this->cardholderName; }
    public function getCardBrand(): string       { return $this->cardBrand; }
    public function getFundingType(): string     { return $this->fundingType; }
    public function getIssuingCountry(): ?string { return $this->issuingCountry; }

    public function isExpired(): bool
    {
        $now    = new \DateTimeImmutable();
        $expiry = new \DateTimeImmutable(sprintf('%04d-%02d-01', $this->expiryYear, $this->expiryMonth));
        $expiry = $expiry->modify('last day of this month')->setTime(23, 59, 59);
        return $now > $expiry;
    }

    public function toArray(): array
    {
        return [
            'last4'          => $this->last4,
            'expiryMonth'    => $this->expiryMonth,
            'expiryYear'     => $this->expiryYear,
            'cardBrand'      => $this->cardBrand,
            'fundingType'    => $this->fundingType,
            'cardholderName' => $this->cardholderName,
            'issuingCountry' => $this->issuingCountry,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            last4:          $data['last4'],
            expiryMonth:    $data['expiryMonth'],
            expiryYear:     $data['expiryYear'],
            cardBrand:      $data['cardBrand'],
            fundingType:    $data['fundingType'],
            cardholderName: $data['cardholderName'] ?? null,
            issuingCountry: $data['issuingCountry'] ?? null,
        );
    }
}
