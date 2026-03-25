<?php

declare(strict_types=1);

namespace Tests\Unit;

use Payroad\Domain\Attempt\AttemptStatus;
use Payroad\Domain\Attempt\PaymentAttemptId;
use Payroad\Domain\Money\Currency;
use Payroad\Domain\Money\Money;
use Payroad\Domain\Payment\PaymentId;
use Payroad\Domain\PaymentFlow\Card\CardPaymentAttempt;
use Payroad\Port\Provider\Card\CardAttemptContext;
use Payroad\Port\Provider\Card\CaptureResult;
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
    ): object {
        $obj                = new \stdClass();
        $obj->id            = $id;
        $obj->client_secret = $clientSecret;
        $obj->status        = $status;
        $obj->payment_method = null;

        return $obj;
    }

    /**
     * Builds a StripeClient stub whose paymentIntents sub-service
     * delegates to the provided closures.
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

    // ── Tests ─────────────────────────────────────────────────────────────────

    public function testSupportsStripe(): void
    {
        $provider = $this->makeProvider($this->makeStripeClient());

        $this->assertTrue($provider->supports('stripe'));
    }

    public function testDoesNotSupportOtherProvider(): void
    {
        $provider = $this->makeProvider($this->makeStripeClient());

        $this->assertFalse($provider->supports('adyen'));
    }

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

        $attemptId = PaymentAttemptId::fromInt(1);
        $paymentId = PaymentId::fromInt(42);
        $amount    = Money::ofMinor(1000, new Currency('USD', 2));

        $attempt = $this->makeProvider($stripe)->initiateCardAttempt(
            $attemptId,
            $paymentId,
            'stripe',
            $amount,
            $this->makeContext(),
        );

        // Verify the PaymentIntent was created with correct amount and currency
        $this->assertIsArray($capturedParams);
        $this->assertSame(1000, $capturedParams['amount']);
        $this->assertSame('usd', $capturedParams['currency']);

        // Verify the returned aggregate type
        $this->assertInstanceOf(CardPaymentAttempt::class, $attempt);

        // Verify the attempt data carries the client_secret
        $data = $attempt->getData();
        $this->assertInstanceOf(StripeCardAttemptData::class, $data);
        $this->assertSame($clientSecret, $data->getClientSecret());
    }

    public function testInitiateCardAttemptSetsProviderReference(): void
    {
        $intentId     = 'pi_test_ref_456';
        $clientSecret = 'pi_test_ref_456_secret';

        $stripe = $this->makeStripeClient([
            'create' => fn(array $p) => $this->makePaymentIntent($intentId, $clientSecret),
        ]);

        $attempt = $this->makeProvider($stripe)->initiateCardAttempt(
            PaymentAttemptId::fromInt(2),
            PaymentId::fromInt(99),
            'stripe',
            Money::ofMinor(500, new Currency('USD', 2)),
            $this->makeContext(),
        );

        $this->assertSame($intentId, $attempt->getProviderReference());
    }

    public function testCaptureAttemptCallsStripe(): void
    {
        $providerRef    = 'pi_test_capture_789';
        $capturedRef    = null;
        $capturedParams = null;

        $successIntent       = new \stdClass();
        $successIntent->id     = $providerRef;
        $successIntent->status = 'succeeded';

        $stripe = $this->makeStripeClient([
            'capture' => function (string $ref, array $params) use ($successIntent, &$capturedRef, &$capturedParams): object {
                $capturedRef    = $ref;
                $capturedParams = $params;
                return $successIntent;
            },
        ]);

        $result = $this->makeProvider($stripe)->captureAttempt($providerRef);

        // Verify Stripe was called with the correct reference
        $this->assertSame($providerRef, $capturedRef);
        $this->assertSame([], $capturedParams);

        // Verify the result maps correctly to SUCCEEDED
        $this->assertInstanceOf(CaptureResult::class, $result);
        $this->assertSame(AttemptStatus::SUCCEEDED, $result->newStatus);
        $this->assertSame('succeeded', $result->providerStatus);
    }
}
