<?php

use App\Enums\PaymentDocumentType;
use App\Models\Address;
use App\Models\Contact;
use App\Models\Order;
use App\Services\WebpayService;
use Laravel\Sanctum\Sanctum;
use Tests\Scenarios\OrderScenario;

use function Pest\Laravel\mock;
use function Pest\Laravel\postJson;

it('checks out without a contact and leaves rutContacto off the order', function () {
    $scenario = OrderScenario::make();
    Sanctum::actingAs($scenario->user, ['api-access']);
    $scenario->addProductToCart();
    $address = Address::factory()->create(['user_id' => $scenario->user->id]);

    mock(WebpayService::class, function ($mock) {
        $mock->shouldReceive('createTransaction')
            ->once()
            ->andReturn([
                'url' => 'https://webpay.test/init',
                'token' => 'test-token-123',
            ]);
    });

    postJson(route('orders.pay'), [
        'address_id' => $address->id,
        'payment_method' => 'transbank',
        'payment_document_type' => PaymentDocumentType::RECEIPT,
    ])->assertOk();

    $order = Order::first();
    expect($order->contact_id)->toBeNull()
        ->and($order->contact_rut)->toBeNull()
        ->and($order->order_meta)->not->toHaveKey('contact');
});

it('rejects a contact that belongs to another entity', function () {
    $scenario = OrderScenario::make();
    Sanctum::actingAs($scenario->user, ['api-access']);
    $scenario->addProductToCart();
    $address = Address::factory()->create(['user_id' => $scenario->user->id]);
    $contact = Contact::factory()->create(['entity_code' => '99999999']);

    postJson(route('orders.pay'), [
        'address_id' => $address->id,
        'contact_id' => $contact->id,
        'payment_method' => 'transbank',
        'payment_document_type' => PaymentDocumentType::RECEIPT,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('contact_id');
});

it('rejects a contact that is not authorized', function () {
    $scenario = OrderScenario::make();
    Sanctum::actingAs($scenario->user, ['api-access']);
    $scenario->addProductToCart();
    $address = Address::factory()->create(['user_id' => $scenario->user->id]);
    $contact = checkoutContact($scenario->user);
    $contact->update(['authorized' => false]);

    postJson(route('orders.pay'), [
        'address_id' => $address->id,
        'contact_id' => $contact->id,
        'payment_method' => 'transbank',
        'payment_document_type' => PaymentDocumentType::RECEIPT,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('contact_id');
});

it('freezes the contact rut on the order', function () {
    $scenario = OrderScenario::make();
    Sanctum::actingAs($scenario->user, ['api-access']);
    $scenario->addProductToCart();
    $address = Address::factory()->create(['user_id' => $scenario->user->id]);
    $contact = checkoutContact($scenario->user);

    mock(WebpayService::class, function ($mock) {
        $mock->shouldReceive('createTransaction')
            ->once()
            ->andReturn([
                'url' => 'https://webpay.test/init',
                'token' => 'test-token-123',
            ]);
    });

    postJson(route('orders.pay'), [
        'address_id' => $address->id,
        'contact_id' => $contact->id,
        'payment_method' => 'transbank',
        'payment_document_type' => PaymentDocumentType::RECEIPT,
    ])->assertOk()
        ->assertJsonPath('data.order.contact_id', $contact->id)
        ->assertJsonPath('data.order.contact_rut', $contact->rut);

    $order = Order::first();
    expect($order->contact_rut)->toBe($contact->rut)
        ->and($order->order_meta['contact']['rut'])->toBe($contact->rut)
        ->and($order->order_meta['contact']['name'])->toBe($contact->name);
});
