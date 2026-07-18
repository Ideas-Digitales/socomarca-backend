<?php

namespace Tests\Scenarios;

class ProfileScenario
{
    public array $addressStructure = [
        'id',
        'address_line1',
        'address_line2',
        'postal_code',
        'is_default',
        'type',
        'phone',
        'contact_name',
        'municipality_name',
        'region_name',
        'alias',
    ];

    public array $profileStructure;

    public function __construct()
    {
        $this->profileStructure = [
            'rut',
            'name',
            'business_name',
            'email',
            'phone',
            'is_active',
            'billing_address' => $this->addressStructure,
            'default_shipping_address' => $this->addressStructure,
        ];
    }

    public static function make(): ProfileScenario
    {
        return new ProfileScenario();
    }
}
