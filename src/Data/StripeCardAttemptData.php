<?php

declare(strict_types=1);

namespace Payroad\Provider\Stripe\Data;

use Payroad\Domain\Channel\Card\CardAttemptData;
use Payroad\Domain\Channel\Card\ThreeDSData;

final class StripeCardAttemptData implements CardAttemptData
{
    public function __construct(
        private readonly string  $clientSecret,
        private readonly ?string $bin            = null,
        private readonly ?string $last4          = null,
        private readonly ?int    $expiryMonth    = null,
        private readonly ?int    $expiryYear     = null,
        private readonly ?string $cardholderName = null,
        private readonly ?string $cardBrand      = null,
        private readonly ?string $fundingType    = null,
        private readonly ?string $issuingCountry = null,
    ) {}

    /** Stripe client_secret for Stripe.js confirmCardPayment(). */
    public function getClientSecret(): string { return $this->clientSecret; }

    public function getClientToken(): ?string { return $this->clientSecret; }

    public function getBin(): ?string            { return $this->bin; }
    public function getLast4(): ?string          { return $this->last4; }
    public function getExpiryMonth(): ?int       { return $this->expiryMonth; }
    public function getExpiryYear(): ?int        { return $this->expiryYear; }
    public function getCardholderName(): ?string { return $this->cardholderName; }
    public function getCardBrand(): ?string      { return $this->cardBrand; }
    public function getFundingType(): ?string    { return $this->fundingType; }
    public function getIssuingCountry(): ?string { return $this->issuingCountry; }
    public function requiresUserAction(): bool   { return false; }
    public function getThreeDSData(): ?ThreeDSData { return null; }

    public function toArray(): array
    {
        return [
            'clientSecret'   => $this->clientSecret,
            'bin'            => $this->bin,
            'last4'          => $this->last4,
            'expiryMonth'    => $this->expiryMonth,
            'expiryYear'     => $this->expiryYear,
            'cardholderName' => $this->cardholderName,
            'cardBrand'      => $this->cardBrand,
            'fundingType'    => $this->fundingType,
            'issuingCountry' => $this->issuingCountry,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            clientSecret:   $data['clientSecret'],
            bin:            $data['bin']            ?? null,
            last4:          $data['last4']          ?? null,
            expiryMonth:    $data['expiryMonth']    ?? null,
            expiryYear:     $data['expiryYear']     ?? null,
            cardholderName: $data['cardholderName'] ?? null,
            cardBrand:      $data['cardBrand']      ?? null,
            fundingType:    $data['fundingType']    ?? null,
            issuingCountry: $data['issuingCountry'] ?? null,
        );
    }
}
