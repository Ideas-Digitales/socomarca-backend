<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PaymentMethods\IndexRequest;
use App\Http\Requests\PaymentMethods\UpdateRequest;
use App\Http\Resources\PaymentMethods\PaymentMethodCollection;
use App\Models\PaymentMethod;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Illuminate\Http\Request;

#[Group('Payment methods', 'List the payment methods offered at checkout and enable or disable them.', weight: 12)]
class PaymentMethodController extends Controller
{
    /**
     * List active payment methods
     *
     * Lists the enabled payment methods. Their `code` is the `payment_method` sent to `POST /orders/pay`.
     */
    public function index()
    {
        // $data = $indexRequest->validated();
        $methods = PaymentMethod::where('active', true)->get();
        $data = new PaymentMethodCollection($methods);
        return $data;
    }

    /**
     * Enable or disable a payment method
     *
     * Disabled methods are no longer listed by `GET /payment-methods`.
     */
    #[PathParameter('id', 'Payment method ID.', type: 'int')]
    public function update(UpdateRequest $updateRequest, $id)
    {
        $data = $updateRequest->validated();

        $paymentMethod = PaymentMethod::find($id);

        if (!$paymentMethod) {
            return response()->json([
                'message' => 'Payment method not found.',
            ], 404);
        }

        $paymentMethod->active = $data['active'];
        $paymentMethod->save();

        return response()->json(['message' => 'The payment method has been updated']);
    }
}
