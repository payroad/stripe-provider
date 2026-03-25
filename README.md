# payroad/stripe-provider

Stripe card payment provider for the [Payroad](https://github.com/payroad/payroad-core) platform.

## Features

- One-step card flow via Stripe.js + PaymentIntents (client-side confirmation)
- Authorize + capture / void (`CapturableCardProviderInterface`)
- Saved payment methods (`TokenizingCardProviderInterface`)
- Webhook signature verification and status mapping
- Full refund support

## Requirements

- PHP 8.2+
- `payroad/payroad-core`
- `stripe/stripe-php`

## Installation

```bash
composer require payroad/stripe-provider
```

## Configuration

```yaml
# config/packages/payroad.yaml
payroad:
  providers:
    stripe:
      factory: Payroad\Provider\Stripe\StripeProviderFactory
      secret_key: '%env(STRIPE_SECRET_KEY)%'
      webhook_secret: '%env(STRIPE_WEBHOOK_SECRET)%'
```

## Payment flow

```
Frontend (Stripe.js)                    Backend
─────────────────────────────────────────────────
POST /api/payments/card/initiate
  ← { clientSecret, attemptId }
confirmCardPayment(clientSecret)        (no call)
  ← Stripe redirects / confirms
                                    POST /webhooks/stripe
                                      payment_intent.succeeded
                                        → Payment SUCCEEDED
```

## Implemented interfaces

| Interface | Description |
|-----------|-------------|
| `OneStepCardProviderInterface` | Client-side confirmation via Stripe.js |
| `CapturableCardProviderInterface` | `captureAttempt()` / `voidAttempt()` |
| `TokenizingCardProviderInterface` | Save and reuse payment methods |
