<?php

declare(strict_types=1);

namespace Payroad\Provider\Stripe;

use Payroad\Domain\Attempt\AttemptStatus;
use Payroad\Domain\Attempt\PaymentAttemptId;
use Payroad\Domain\Money\Money;
use Payroad\Domain\Payment\CustomerId;
use Payroad\Domain\Payment\PaymentId;
use Payroad\Domain\Channel\Card\CardPaymentAttempt;
use Payroad\Domain\Channel\Card\CardRefund;
use Payroad\Domain\Channel\Card\CardSavedPaymentMethod;
use Payroad\Domain\Refund\RefundId;
use Payroad\Domain\SavedPaymentMethod\SavedPaymentMethodId;
use Payroad\Port\Provider\Card\CapturableCardProviderInterface;
use Payroad\Port\Provider\Card\CardAttemptContext;
use Payroad\Port\Provider\Card\CardRefundContext;
use Payroad\Port\Provider\Card\CaptureResult;
use Payroad\Port\Provider\Card\OneStepCardProviderInterface;
use Payroad\Port\Provider\Card\TokenizingCardProviderInterface;
use Payroad\Port\Provider\Card\VoidResult;
use Payroad\Port\Provider\RefundWebhookResult;
use Payroad\Port\Provider\WebhookEvent;
use Payroad\Port\Provider\WebhookResult;
use Payroad\Provider\Stripe\Data\StripeCardAttemptData;
use Payroad\Provider\Stripe\Data\StripeCardRefundData;
use Payroad\Provider\Stripe\Data\StripeCardSavedMethodData;
use Payroad\Provider\Stripe\Mapper\StripeStatusMapper;
use Stripe\StripeClient;

final class StripeCardProvider implements OneStepCardProviderInterface, CapturableCardProviderInterface, TokenizingCardProviderInterface
{
    private ?StripeClient $stripeClient = null;

    public function __construct(
        private readonly string             $secretKey,
        private readonly string             $webhookSecret,
        private readonly StripeStatusMapper $mapper = new StripeStatusMapper(),
    ) {}

    private function stripe(): StripeClient
    {
        return $this->stripeClient ??= new StripeClient($this->secretKey);
    }

    public function supports(string $providerName): bool
    {
        return $providerName === 'stripe';
    }

    // ── Attempt initiation ────────────────────────────────────────────────────

    /**
     * Variant B: creates a PaymentIntent without confirming it.
     * The client_secret is returned to the frontend so Stripe.js can
     * collect card details and handle 3DS transparently.
     */
    public function initiateCardAttempt(
        PaymentAttemptId   $id,
        PaymentId          $paymentId,
        string             $providerName,
        Money              $amount,
        CardAttemptContext $context,
    ): CardPaymentAttempt {
        $intent = $this->stripe()->paymentIntents->create(
            [
                'amount'         => $amount->getMinorAmount(),
                'currency'       => strtolower($amount->getCurrency()->code),
                'capture_method' => 'automatic',
                'metadata'       => [
                    'payroad_attempt_id' => (string) $id,
                    'payroad_payment_id' => (string) $paymentId,
                ],
            ],
            ['idempotency_key' => (string) $id],
        );

        $data    = new StripeCardAttemptData(clientSecret: $intent->client_secret);
        $attempt = CardPaymentAttempt::create($id, $paymentId, $providerName, $amount, $data);
        $attempt->setProviderReference($intent->id);

        return $attempt;
    }

    /**
     * Off-session charge using a stored Stripe PaymentMethod token.
     * Confirms the PaymentIntent immediately on the server side.
     */
    public function initiateAttemptWithSavedMethod(
        PaymentAttemptId $id,
        PaymentId        $paymentId,
        string           $providerName,
        Money            $amount,
        string           $providerToken,
    ): CardPaymentAttempt {
        $intent = $this->stripe()->paymentIntents->create(
            [
                'amount'         => $amount->getMinorAmount(),
                'currency'       => strtolower($amount->getCurrency()->code),
                'payment_method' => $providerToken,
                'confirm'        => true,
                'off_session'    => true,
                'metadata'       => [
                    'payroad_attempt_id' => (string) $id,
                    'payroad_payment_id' => (string) $paymentId,
                ],
            ],
            ['idempotency_key' => (string) $id],
        );

        $card = $intent->payment_method?->card;
        $data = new StripeCardAttemptData(
            clientSecret:   $intent->client_secret,
            bin:            $card?->iin ?? null,
            last4:          $card?->last4 ?? null,
            expiryMonth:    isset($card->exp_month) ? (int) $card->exp_month : null,
            expiryYear:     isset($card->exp_year)  ? (int) $card->exp_year  : null,
            cardBrand:      $card?->brand ?? null,
            fundingType:    $card?->funding ?? null,
            issuingCountry: $card?->country ?? null,
        );

        $attempt = CardPaymentAttempt::create($id, $paymentId, $providerName, $amount, $data);
        $attempt->setProviderReference($intent->id);

        return $attempt;
    }

    // ── Capture / Void ────────────────────────────────────────────────────────

    public function captureAttempt(string $providerReference, ?Money $amount = null): CaptureResult
    {
        $params = [];
        if ($amount !== null) {
            $params['amount_to_capture'] = $amount->getMinorAmount();
        }

        $intent = $this->stripe()->paymentIntents->capture($providerReference, $params);

        return new CaptureResult(
            newStatus:      $intent->status === 'succeeded'
                                ? AttemptStatus::SUCCEEDED
                                : AttemptStatus::PROCESSING,
            providerStatus: $intent->status,
        );
    }

    public function voidAttempt(string $providerReference): VoidResult
    {
        $intent = $this->stripe()->paymentIntents->cancel($providerReference);

        return new VoidResult(
            newStatus:      AttemptStatus::CANCELED,
            providerStatus: $intent->status,
        );
    }

    // ── Refund ────────────────────────────────────────────────────────────────

    public function initiateRefund(
        RefundId          $id,
        PaymentId         $paymentId,
        PaymentAttemptId  $originalAttemptId,
        string            $providerName,
        Money             $amount,
        string            $originalProviderReference,
        CardRefundContext  $context,
    ): CardRefund {
        $params = [
            'payment_intent' => $originalProviderReference,
            'amount'         => $amount->getMinorAmount(),
        ];

        if ($context->reason !== null) {
            $params['reason'] = $context->reason;
        }

        $refund = $this->stripe()->refunds->create($params);

        $data       = new StripeCardRefundData(
            reason:                  $refund->reason,
            acquirerReferenceNumber: $refund->acquirer_reference_number ?? null,
        );
        $cardRefund = CardRefund::create($id, $paymentId, $originalAttemptId, $providerName, $amount, $data);
        $cardRefund->setProviderReference($refund->id);

        return $cardRefund;
    }

    // ── Save payment method ───────────────────────────────────────────────────

    public function savePaymentMethod(
        SavedPaymentMethodId $id,
        CustomerId           $customerId,
        string               $originalProviderReference,
    ): CardSavedPaymentMethod {
        $intent = $this->stripe()->paymentIntents->retrieve(
            $originalProviderReference,
            ['expand' => ['payment_method']],
        );

        $pm   = $intent->payment_method
            ?? throw new \RuntimeException("No payment method on PaymentIntent {$originalProviderReference}");
        $card = $pm->card
            ?? throw new \RuntimeException("PaymentMethod {$pm->id} has no card details");

        $data = new StripeCardSavedMethodData(
            last4:          $card->last4,
            expiryMonth:    (int) $card->exp_month,
            expiryYear:     (int) $card->exp_year,
            cardBrand:      $card->brand,
            fundingType:    $card->funding,
            cardholderName: $pm->billing_details?->name ?? null,
            issuingCountry: $card->country ?? null,
        );

        return CardSavedPaymentMethod::create($id, $customerId, 'stripe', $pm->id, $data);
    }

    // ── Webhooks ──────────────────────────────────────────────────────────────

    /**
     * Verifies Stripe webhook signature, routes by event type, and returns the
     * appropriate WebhookEvent subtype (or null for events we intentionally ignore).
     *
     * Convention: the raw HTTP body must be passed via $headers['stripe-raw-body']
     * because Stripe signature verification requires the original unmodified payload.
     */
    public function parseIncomingWebhook(array $payload, array $headers): ?WebhookEvent
    {
        $rawBody   = $this->extractHeader($headers, 'raw-body');
        $sigHeader = $this->extractHeader($headers, 'stripe-signature');

        $event = \Stripe\Webhook::constructEvent($rawBody, $sigHeader, $this->webhookSecret);
        $type  = $event->type;

        if (str_starts_with($type, 'payment_intent.')) {
            $newStatus = $this->mapper->mapPaymentIntentEvent($type);
            if ($newStatus === null) {
                return null;
            }
            $intent = $event->data->object;
            return new WebhookResult(
                providerReference: $intent->id,
                newStatus:         $newStatus,
                providerStatus:    $intent->status,
                statusChanged:     true,
            );
        }

        if (str_starts_with($type, 'refund.') || str_starts_with($type, 'charge.refund')) {
            $newStatus = $this->mapper->mapRefundEvent($type);
            if ($newStatus === null) {
                return null;
            }
            $refund = $event->data->object;
            return new RefundWebhookResult(
                providerReference: $refund->id,
                newStatus:         $newStatus,
                providerStatus:    $refund->status,
                statusChanged:     true,
            );
        }

        return null;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function extractHeader(array $headers, string $key): string
    {
        // Symfony HttpFoundation passes headers as arrays of values
        $value = $headers[$key] ?? null;
        if (is_array($value)) {
            $value = $value[0] ?? null;
        }
        if ($value === null || $value === '') {
            throw new \InvalidArgumentException("Missing required header: {$key}");
        }
        return $value;
    }
}
