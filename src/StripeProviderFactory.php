<?php

declare(strict_types=1);

namespace Payroad\Provider\Stripe;

use Payroad\Port\Provider\ProviderFactoryInterface;
use Stripe\StripeClient;

final class StripeProviderFactory implements ProviderFactoryInterface
{
    public function create(array $config): StripeCardProvider
    {
        return new StripeCardProvider(
            new StripeClient($config['secret_key']),
            $config['webhook_secret'],
        );
    }
}
