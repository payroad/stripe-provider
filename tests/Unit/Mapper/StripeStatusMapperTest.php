<?php

declare(strict_types=1);

namespace Tests\Unit\Mapper;

use Payroad\Domain\Attempt\AttemptStatus;
use Payroad\Provider\Stripe\Mapper\StripeStatusMapper;
use PHPUnit\Framework\TestCase;

final class StripeStatusMapperTest extends TestCase
{
    private StripeStatusMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new StripeStatusMapper();
    }

    public function testMapsSucceeded(): void
    {
        $this->assertSame(
            AttemptStatus::SUCCEEDED,
            $this->mapper->mapPaymentIntentEvent('payment_intent.succeeded')
        );
    }

    public function testMapsPaymentFailed(): void
    {
        $this->assertSame(
            AttemptStatus::FAILED,
            $this->mapper->mapPaymentIntentEvent('payment_intent.payment_failed')
        );
    }

    public function testMapsProcessing(): void
    {
        $this->assertSame(
            AttemptStatus::PROCESSING,
            $this->mapper->mapPaymentIntentEvent('payment_intent.processing')
        );
    }

    public function testMapsCanceled(): void
    {
        $this->assertSame(
            AttemptStatus::CANCELED,
            $this->mapper->mapPaymentIntentEvent('payment_intent.canceled')
        );
    }

    public function testMapsAuthorized(): void
    {
        $this->assertSame(
            AttemptStatus::AUTHORIZED,
            $this->mapper->mapPaymentIntentEvent('payment_intent.amount_capturable_updated')
        );
    }

    public function testReturnsNullForUnknownEvent(): void
    {
        $this->assertNull($this->mapper->mapPaymentIntentEvent('payment_intent.created'));
        $this->assertNull($this->mapper->mapPaymentIntentEvent('payment_intent.requires_action'));
    }
}
