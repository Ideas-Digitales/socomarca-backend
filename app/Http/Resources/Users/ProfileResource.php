<?php

namespace App\Http\Resources\Users;

use App\Http\Resources\Addresses\AddressResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProfileResource extends JsonResource
{
    /**
     * Redeclared so that disabling the wrapping does not affect the other resources.
     *
     * @var string|null
     */
    public static $wrap = null;

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $billingAddress = $this->billing_address ?
            $this->billing_address->toResource(AddressResource::class) : null;
        $defaultShippingAddress = $this->default_shipping_address ?
            $this->default_shipping_address->toResource(AddressResource::class) : null;

        return [
            /** @var string|null */
            'rut'=> $this->rut,
            /** @var string */
            'name' => $this->name,
            /** @var string|null */
            'business_name'=> $this->business_name,
            /** @var string */
            'email'=> $this->email,
            /** @var string|null */
            'phone'=> $this->phone,
            /** @var bool */
            'is_active'=> $this->is_active,
            /**
             * Random ERP branch type: `P` primary, `S` secondary. `null` for internal users.
             *
             * @var 'P'|'S'|null
             */
            'branch_type' => $this->branch_type,
            /**
             * Whether the user can place orders for other branches (`GET /branches`): a primary branch with
             * at least one active secondary branch. Same value as in the login response.
             *
             * @var bool
             */
            'can_order_for_branches' => $this->canOrderForBranches(),
            /** @var AddressResource|null */
            'billing_address' => $billingAddress,
            /**
             * Default shipping address.
             *
             * @var AddressResource|null
             */
            'default_shipping_address' => $defaultShippingAddress,
            /**
             * Firebase Cloud Messaging token of the user's device, for push notifications.
             *
             * @var string|null
             */
            'fcm_token' => $this->fcm_token,
        ];
    }
}
