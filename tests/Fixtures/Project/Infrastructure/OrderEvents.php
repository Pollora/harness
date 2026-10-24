<?php

declare(strict_types=1);

namespace Pollora\Harness\Tests\Fixtures\Project\Infrastructure;

final class OrderEvents
{
    public const string STATUS_LABEL = 'pollora/orders/status_label';

    public function placed(int $order): int
    {
        do_action('pollora/orders/placed', $order);
        do_action('pollora/order_placed', $order);

        // A package name, not a hook.
        $package = 'pollora/framework';

        return (int) apply_filters('pollora/orders/total', $order, $package);
    }
}
