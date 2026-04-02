<?php

declare(strict_types=1);

namespace Tests\Unit;

use Payroad\Domain\Attempt\AttemptStatus;
use Payroad\Domain\Attempt\PaymentAttemptId;
use Payroad\Domain\Money\Currency;
use Payroad\Domain\Money\Money;
use Payroad\Domain\Payment\CustomerId;
use Payroad\Domain\Payment\PaymentId;
use Payroad\Domain\Channel\Card\CardPaymentAttempt;
use Payroad\Domain\Channel\Card\CardRefund;
use Payroad\Domain\Channel\Card\CardSavedPaymentMethod;
use Payroad\Domain\Refund\RefundId;
use Payroad\Domain\SavedPaymentMethod\SavedPaymentMethodId;
use Payroad\Port\Provider\Card\CardAttemptContext;
use Payroad\Port\Provider\Card\CardRefundContext;
use Payroad\Port\Provider\Card\CaptureResult;
use Payroad\Port\Provider\Card\VoidResult;
use Payroad\Provider\Stripe\Data\StripeCardAttemptData;
use Payroad\Provider\Stripe\StripeCardProvider;
use PHPUnit\Framework\TestCase;
use Stripe\StripeClient;

final class StripeCardProviderTest extends TestCase
{
    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Builds a minimal PaymentIntent-like stdClass with the fields
     * that StripeCardProvider reads.
     */
    private function makePaymentIntent(
        string $id,
        string $clientSecret,
        string $status = 'requires_payment_method',
        ?object $paymentMethod = null,
    ): object {
        $obj                 = new \stdClass();
        $obj->id             = $id;
        $obj->client_secret  = $clientSecret;
        $obj->status         = $status;
        $obj->payment_method = $paymentMethod;

        return $obj;
    }

    /**
     * Builds a mock payment_method object with card details.
     */
    private function makePaymentMethod(
        string $pmId,
        string $last4 = '4242',
        int    $expMonth = 12,
        int    $expYear  = 2030,
        string $brand    = 'visa',
        string $funding  = 'credit',
        string $country  = 'US',
        ?string $holderName = 'Jane Doe',
    ): object {
        $card          = new \stdClass();
        $card->last4   = $last4;
        $card->exp_month = $expMonth;
        $card->exp_year  = $expYear;
        $card->brand   = $brand;
        $card->funding = $funding;
        $card->country = $country;
        $card->iin     = null;

        $billing         = new \stdClass();
        $billing->name   = $holderName;

        $pm                  = new \stdClass();
        $pm->id              = $pmId;
        $pm->card            = $card;
        $pm->billing_details = $billing;

        return $pm;
    }

    /**
     * Builds a StripeClient stub whose sub-services delegate to the provided closures.
     *
     * @param array<string, \Closure> $paymentIntentsMethods
     * @param array<string, \Closure> $refundsMethods
     */
    private function makeStripeClient(
        array $paymentIntentsMethods = [],
        array $refundsMethods        = [],
    ): StripeClient {
        $paymentIntentsService = new class($paymentIntentsMethods) {
            public function __construct(private array $methods) {}

            public function __call(string $name, array $args): mixed
            {
                if (isset($this->methods[$name])) {
                    return ($this->methods[$name])(...$args);
                }
                throw new \BadMethodCallException("Unexpected call to paymentIntents->{$name}()");
            }
        };

        $refundsService = new class($refundsMethods) {
            public function __construct(private array $methods) {}

            public function __call(string $name, array $args): mixed
            {
                if (isset($this->methods[$name])) {
                    return ($this->methods[$name])(...$args);
                }
                throw new \BadMethodCallException("Unexpected call to refunds->{$name}()");
            }
        };

        /** @var StripeClient $client */
        $client = new class($paymentIntentsService, $refundsService) extends StripeClient {
            public function __construct(
                private object $piService,
                private object $refService,
            ) {
                // Do NOT call parent::__construct() — that would require an API key
                // and perform HTTP setup. We only use magic property access below.
            }

            public function __get($name)
            {
                return match ($name) {
                    'paymentIntents' => $this->piService,
                    'refunds'        => $this->refService,
                    default          => throw new \BadMethodCallException("Unknown Stripe service: {$name}"),
                };
            }
        };

        return $client;
    }

    private function makeProvider(StripeClient $stripe): StripeCardProvider
    {
        return new StripeCardProvider($stripe, 'whsec_test');
    }

    private function makeContext(): CardAttemptContext
    {
        return new CardAttemptContext(
            customerIp:       '127.0.0.1',
            browserUserAgent: 'PHPUnit/Test',
        );
    }

    private function makeAmount(): Money
    {
        return Money::ofMinor(1000, new Currency('USD', 2));
    }

    // ── supports ──────────────────────────────────────────────────────────────

    public function testSupportsStripe(): void
    {
        $this->assertTrue($this->makeProvider($this->makeStripeClient())->supports('stripe'));
    }

    public function testDoesNotSupportOtherProvider(): void
    {
        $this->assertFalse($this->makeProvider($this->makeStripeClient())->supports('adyen'));
    }

    // ── initiateCardAttempt ───────────────────────────────────────────────────

    public function testInitiateCardAttemptCreatesPaymentIntent(): void
    {
        $intentId     = 'pi_test_abc123';
        $clientSecret = 'pi_test_abc123_secret_xyz';

        $capturedParams = null;
        $stripe = $this->makeStripeClient([
            'create' => function (array $params) use ($intentId, $clientSecret, &$capturedParams): object {
                $capturedParams = $params;
                return $this->makePaymentIntent($intentId, $clientSecret);
            },
        ]);

        $attempt = $this->makeProvider($stripe)->initiateCardAttempt(
            PaymentAttemptId::fromInt(1),
            PaymentId::fromInt(42),
            'stripe',
            $this->makeAmount(),
            $this->makeContext(),
        );

        $this->assertSame(1000, $capturedParams['amount']);
        $this->assertSame('usd', $capturedParams['currency']);
        $this->assertInstanceOf(CardPaymentAttempt::class, $attempt);

        $data = $attempt->getData();
        $this->assertInstanceOf(StripeCardAttemptData::class, $data);
        $this->assertSame($clientSecret, $data->getClientSecret());
    }

    public function testInitiateCardAttemptSetsProviderReference(): void
    {
        $intentId = 'pi_test_ref_456';

        $stripe = $this->makeStripeClient([
            'create' => fn(array $p) => $this->makePaymentIntent($intentId, 'secret'),
        ]);

        $attempt = $this->makeProvider($stripe)->initiateCardAttempt(
            PaymentAttemptId::fromInt(2),
            PaymentId::fromInt(99),
            'stripe',
            $this->makeAmount(),
            $this->makeContext(),
        );

        $this->assertSame($intentId, $attempt->getProviderReference());
    }

    // ── initiateAttemptWithSavedMethod ────────────────────────────────────────

    public function testInitiateAttemptWithSavedMethodCreatesAttempt(): void
    {
        $intentId     = 'pi_saved_789';
        $clientSecret = 'pi_saved_789_secret';
        $pm           = $this->makePaymentMethod('pm_abc');

        $capturedParams = null;
        $stripe = $this->makeStripeClient([
            'create' => function (array $params) use ($intentId, $clientSecret, $pm, &$capturedParams): object {
                $capturedParams = $params;
                return $this->makePaymentIntent($intentId, $clientSecret, 'succeeded', $pm);
            },
        ]);

        $attempt = $this->makeProvider($stripe)->initiateAttemptWithSavedMethod(
            PaymentAttemptId::fromInt(3),
            PaymentId::fromInt(10),
            'stripe',
            $this->makeAmount(),
            'pm_abc',
        );

        $this->assertTrue($capturedParams['confirm']);
        $this->assertTrue($capturedParams['off_session']);
        $this->assertSame('pm_abc', $capturedParams['payment_method']);
        $this->assertInstanceOf(CardPaymentAttempt::class, $attempt);
        $this->assertSame($intentId, $attempt->getProviderReference());
    }

    public function testInitiateAttemptWithSavedMethodPopulatesCardData(): void
    {
        $pm = $this->makePaymentMethod('pm_xyz', last4: '1234', brand: 'mastercard');

        $stripe = $this->makeStripeClient([
            'create' => fn(array $p) => $this->makePaymentIntent('pi_x', 'sec', 'succeeded', $pm),
        ]);

        $attempt = $this->makeProvider($stripe)->initiateAttemptWithSavedMethod(
            PaymentAttemptId::fromInt(4),
            PaymentId::fromInt(11),
            'stripe',
            $this->makeAmount(),
            'pm_xyz',
        );

        /** @var StripeCardAttemptData $data */
        $data = $attempt->getData();
        $this->assertSame('1234', $data->getLast4());
        $this->assertSame('mastercard', $data->getCardBrand());
    }

    // ── captureAttempt ────────────────────────────────────────────────────────

    public function testCaptureAttemptCallsStripe(): void
    {
        $providerRef = 'pi_capture_789';

        $capturedRef    = null;
        $capturedParams = null;
        $intent         = new \stdClass();
        $intent->status = 'succeeded';

        $stripe = $this->makeStripeClient([
            'capture' => function (string $ref, array $params) use ($intent, &$capturedRef, &$capturedParams): object {
                $capturedRef    = $ref;
                $capturedParams = $params;
                return $intent;
            },
        ]);

        $result = $this->makeProvider($stripe)->captureAttempt($providerRef);

        $this->assertSame($providerRef, $capturedRef);
        $this->assertSame([], $capturedParams);
        $this->assertInstanceOf(CaptureResult::class, $result);
        $this->assertSame(AttemptStatus::SUCCEEDED, $result->newStatus);
        $this->assertSame('succeeded', $result->providerStatus);
    }

    public function testPartialCapturePassesAmount(): void
    {
        $capturedParams = null;
        $intent         = new \stdClass();
        $intent->status = 'succeeded';

        $stripe = $this->makeStripeClient([
            'capture' => function (string $ref, array $params) use ($intent, &$capturedParams): object {
                $capturedParams = $params;
                return $intent;
            },
        ]);

        $partialAmount = Money::ofMinor(500, new Currency('USD', 2));
        $this->makeProvider($stripe)->captureAttempt('pi_partial', $partialAmount);

        $this->assertSame(500, $capturedParams['amount_to_capture']);
    }

    // ── voidAttempt ───────────────────────────────────────────────────────────

    public function testVoidAttemptCancelsPaymentIntent(): void
    {
        $providerRef = 'pi_void_abc';

        $canceledRef = null;
        $intent      = new \stdClass();
        $intent->status = 'canceled';

        $stripe = $this->makeStripeClient([
            'cancel' => function (string $ref) use ($intent, &$canceledRef): object {
                $canceledRef = $ref;
                return $intent;
            },
        ]);

        $result = $this->makeProvider($stripe)->voidAttempt($providerRef);

        $this->assertSame($providerRef, $canceledRef);
        $this->assertInstanceOf(VoidResult::class, $result);
        $this->assertSame(AttemptStatus::CANCELED, $result->newStatus);
        $this->assertSame('canceled', $result->providerStatus);
    }

    // ── initiateRefund ────────────────────────────────────────────────────────

    public function testInitiateRefundCreatesStripeRefund(): void
    {
        $refundId   = 're_test_123';
        $providerRef = 'pi_original_456';

        $capturedParams = null;
        $refund         = new \stdClass();
        $refund->id     = $refundId;
        $refund->reason = 'requested_by_customer';
        $refund->acquirer_reference_number = null;

        $stripe = $this->makeStripeClient(
            refundsMethods: [
                'create' => function (array $params) use ($refund, &$capturedParams): object {
                    $capturedParams = $params;
                    return $refund;
                },
            ],
        );

        $cardRefund = $this->makeProvider($stripe)->initiateRefund(
            id:                        RefundId::fromInt(1),
            paymentId:                 PaymentId::fromInt(42),
            originalAttemptId:         PaymentAttemptId::fromInt(7),
            providerName:              'stripe',
            amount:                    Money::ofMinor(500, new Currency('USD', 2)),
            originalProviderReference: $providerRef,
            context:                   new CardRefundContext(reason: 'requested_by_customer'),
        );

        $this->assertSame($providerRef, $capturedParams['payment_intent']);
        $this->assertSame(500, $capturedParams['amount']);
        $this->assertSame('requested_by_customer', $capturedParams['reason']);
        $this->assertInstanceOf(CardRefund::class, $cardRefund);
        $this->assertSame($refundId, $cardRefund->getProviderReference());
    }

    public function testInitiateRefundWithoutReasonOmitsReasonParam(): void
    {
        $capturedParams = null;
        $refund         = new \stdClass();
        $refund->id     = 're_noreason';
        $refund->reason = null;
        $refund->acquirer_reference_number = null;

        $stripe = $this->makeStripeClient(
            refundsMethods: [
                'create' => function (array $params) use ($refund, &$capturedParams): object {
                    $capturedParams = $params;
                    return $refund;
                },
            ],
        );

        $this->makeProvider($stripe)->initiateRefund(
            id:                        RefundId::fromInt(2),
            paymentId:                 PaymentId::fromInt(42),
            originalAttemptId:         PaymentAttemptId::fromInt(7),
            providerName:              'stripe',
            amount:                    Money::ofMinor(500, new Currency('USD', 2)),
            originalProviderReference: 'pi_x',
            context:                   new CardRefundContext(),
        );

        $this->assertArrayNotHasKey('reason', $capturedParams);
    }

    // ── savePaymentMethod ─────────────────────────────────────────────────────

    public function testSavePaymentMethodTokenizesCard(): void
    {
        $pm     = $this->makePaymentMethod('pm_save_111', last4: '9999', brand: 'amex');
        $intent = $this->makePaymentIntent('pi_save_abc', 'secret', 'succeeded', $pm);

        $stripe = $this->makeStripeClient([
            'retrieve' => fn(string $ref, array $params) => $intent,
        ]);

        $saved = $this->makeProvider($stripe)->savePaymentMethod(
            id:                        SavedPaymentMethodId::generate(),
            customerId:                CustomerId::of('cust-1'),
            originalProviderReference: 'pi_save_abc',
        );

        $this->assertInstanceOf(CardSavedPaymentMethod::class, $saved);
        $this->assertSame('pm_save_111', $saved->getProviderToken());

        $data = $saved->getData();
        $this->assertSame('9999', $data->getLast4());
        $this->assertSame('amex', $data->getCardBrand());
        $this->assertSame('Jane Doe', $data->getCardholderName());
    }

    public function testSavePaymentMethodThrowsIfNoPaymentMethod(): void
    {
        $intent = $this->makePaymentIntent('pi_nope', 'secret');  // payment_method = null

        $stripe = $this->makeStripeClient([
            'retrieve' => fn(string $ref, array $params) => $intent,
        ]);

        $this->expectException(\RuntimeException::class);

        $this->makeProvider($stripe)->savePaymentMethod(
            id:                        SavedPaymentMethodId::generate(),
            customerId:                CustomerId::of('cust-2'),
            originalProviderReference: 'pi_nope',
        );
    }

    // ── parseIncomingWebhook ──────────────────────────────────────────────────

    public function testParseIncomingWebhookThrowsOnMissingRawBody(): void
    {
        $provider = $this->makeProvider($this->makeStripeClient());

        $this->expectException(\InvalidArgumentException::class);

        // raw-body header missing — must throw before reaching Stripe SDK
        $provider->parseIncomingWebhook(
            payload: [],
            headers: ['stripe-signature' => ['t=123,v1=abc']],
        );
    }

    public function testParseIncomingWebhookThrowsOnMissingSignatureHeader(): void
    {
        $provider = $this->makeProvider($this->makeStripeClient());

        $this->expectException(\InvalidArgumentException::class);

        $provider->parseIncomingWebhook(
            payload: [],
            headers: ['raw-body' => ['{}']],
        );
    }
}
