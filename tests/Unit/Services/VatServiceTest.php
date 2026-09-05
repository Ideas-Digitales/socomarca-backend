<?php

use App\Models\Siteinfo;
use App\Services\VatService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it("falls back to the configured rate when siteinfo has no VAT entry", function () {
    config()->set('vat.rate', 19);

    expect((new VatService())->rate())->toBe(19.0);
});

it("reads the rate stored in siteinfo", function () {
    Siteinfo::updateOrCreate(
        ['key' => VatService::SETTINGS_KEY],
        ['value' => ['rate' => 12.5]]
    );

    expect((new VatService())->rate())->toBe(12.5);
});

it("ignores a non numeric stored rate", function () {
    config()->set('vat.rate', 19);
    Siteinfo::updateOrCreate(
        ['key' => VatService::SETTINGS_KEY],
        ['value' => ['rate' => 'abc']]
    );

    expect((new VatService())->rate())->toBe(19.0);
});

it("adds the VAT to a net amount", function () {
    $vat = new VatService();

    expect($vat->applyTo(1000, 19))->toBe(1190.0);
    expect($vat->amountFor(1000, 19))->toBe(190.0);
    expect($vat->applyTo(1000, 0))->toBe(1000.0);
});

it("rounds to the requested precision", function () {
    $vat = new VatService();

    expect($vat->applyTo(333.33, 19, 0))->toBe(397.0);
    expect($vat->applyTo(333.33, 19, 2))->toBe(396.66);
});

it("recovers the net amount contained in a gross one", function () {
    $vat = new VatService();

    expect($vat->netFrom(1190, 19))->toBe(1000.0);
    expect($vat->netFrom(1000, 0))->toBe(1000.0);
});
