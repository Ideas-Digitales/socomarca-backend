<?php

use App\Enums\BranchType;
use App\Mail\OrderConfirmationMail;
use App\Models\Address;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('s3');
});

/**
 * @return list<string>
 */
function orderNotificationBodies(Order $order): array
{
    $order->loadMissing(['user', 'customer', 'payments.paymentMethod', 'orderDetails.product']);
    $forAnotherBranch = $order->user !== null
        && $order->customer !== null
        && ! $order->user->is($order->customer);

    return [
        view('emails.order-confirmation', [
            'order' => $order,
            'audience' => $forAnotherBranch
                ? OrderConfirmationMail::AUDIENCE_BUYER
                : OrderConfirmationMail::AUDIENCE_SELF,
        ])->render(),
        view('emails.order-completed', ['order' => $order])->render(),
    ];
}

function makeBranch(array $attributes = []): User
{
    return User::factory()->create($attributes + [
        'phone' => '56911111111',
        'email' => 'compras@cliente.cl',
    ]);
}

it('shows the same address twice when a primary branch buys for itself', function () {
    $branch = makeBranch([
        'name' => 'Casa Matriz',
        'branch_type' => BranchType::PRIMARY,
        'phone' => '56911111111',
        'email' => 'matriz@cliente.cl',
    ]);
    $order = Order::factory()->create([
        'user_id' => $branch->id,
        'customer_id' => $branch->id,
        'order_meta' => [
            'address' => [
                'address_line1' => 'Casa Matriz 100',
                'postal_code' => '8320000',
            ],
        ],
    ]);

    foreach (orderNotificationBodies($order) as $body) {
        expect(substr_count($body, 'Casa Matriz 100'))->toBe(2)
            ->and(substr_count($body, 'Casa Matriz'))->toBeGreaterThanOrEqual(2)
            ->and(substr_count($body, 'matriz@cliente.cl'))->toBe(2);
    }
});

it('shows the same address twice when a secondary branch buys', function () {
    $branch = makeBranch([
        'name' => 'Local Norte',
        'branch_type' => BranchType::SECONDARY,
        'phone' => '56922222222',
        'email' => 'norte@cliente.cl',
    ]);
    $order = Order::factory()->create([
        'user_id' => $branch->id,
        'customer_id' => $branch->id,
        'order_meta' => [
            'address' => [
                'address_line1' => 'Local Norte 200',
                'postal_code' => '8320001',
            ],
        ],
    ]);

    foreach (orderNotificationBodies($order) as $body) {
        expect(substr_count($body, 'Local Norte 200'))->toBe(2)
            ->and(substr_count($body, 'norte@cliente.cl'))->toBe(2);
    }
});

it('bills the primary branch and ships to the secondary branch', function () {
    $primary = makeBranch([
        'name' => 'Casa Matriz',
        'branch_type' => BranchType::PRIMARY,
        'phone' => '56911111111',
        'email' => 'matriz@cliente.cl',
    ]);
    $secondary = makeBranch([
        'name' => 'Local Norte',
        'branch_type' => BranchType::SECONDARY,
        'phone' => '56922222222',
        'email' => 'norte@cliente.cl',
    ]);
    $order = Order::factory()->create([
        'user_id' => $primary->id,
        'customer_id' => $secondary->id,
        'order_meta' => [
            'address' => [
                'address_line1' => 'Local Norte 200',
                'postal_code' => '8320001',
            ],
            'billing_address' => [
                'address_line1' => 'Casa Matriz 100',
                'postal_code' => '8320000',
            ],
        ],
    ]);

    foreach (orderNotificationBodies($order) as $body) {
        expect(substr_count($body, 'Casa Matriz 100'))->toBe(1)
            ->and(substr_count($body, 'Local Norte 200'))->toBe(1)
            ->and(strpos($body, 'Casa Matriz 100'))->toBeLessThan(strpos($body, 'Local Norte 200'))
            ->and(substr_count($body, 'matriz@cliente.cl'))->toBe(1)
            ->and(substr_count($body, 'norte@cliente.cl'))->toBe(1);
    }
});

it('uses the primary branch address when an older order has no billing snapshot', function () {
    $primary = makeBranch([
        'name' => 'Casa Matriz',
        'branch_type' => BranchType::PRIMARY,
        'email' => 'matriz@cliente.cl',
    ]);
    Address::factory()->create([
        'user_id' => $primary->id,
        'is_synced' => false,
        'address_line1' => 'Otra calle 1',
        'type' => 'shipping',
    ]);
    Address::factory()->create([
        'user_id' => $primary->id,
        'is_synced' => true,
        'address_line1' => 'Casa Matriz 100',
        'type' => 'shipping',
    ]);
    $secondary = makeBranch([
        'name' => 'Local Norte',
        'branch_type' => BranchType::SECONDARY,
        'email' => 'norte@cliente.cl',
    ]);
    $order = Order::factory()->create([
        'user_id' => $primary->id,
        'customer_id' => $secondary->id,
        'order_meta' => [
            'address' => [
                'address_line1' => 'Local Norte 200',
            ],
        ],
    ]);

    foreach (orderNotificationBodies($order) as $body) {
        expect(substr_count($body, 'Casa Matriz 100'))->toBe(1)
            ->and(substr_count($body, 'Otra calle 1'))->toBe(0)
            ->and(substr_count($body, 'Local Norte 200'))->toBe(1);
    }
});
