<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Confirmación de compra</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #ffffff;
            margin: 0;
            padding: 0;
            color: #333333;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        .header {
            text-align: center;
            padding: 24px 0 16px;
        }
        .header img {
            max-width: 220px;
        }
        .divider {
            height: 4px;
            background-color: #6cb409;
            margin: 16px 0 32px;
        }
        .cart-icon-wrapper {
            text-align: center;
            margin: 24px 0;
        }
        .cart-icon {
            display: inline-block;
            width: 90px;
            height: 90px;
        }
        .intro {
            text-align: center;
            font-size: 18px;
            line-height: 1.5;
            margin: 24px 0 12px;
        }
        .order-summary {
            text-align: center;
            font-size: 18px;
            margin-bottom: 32px;
        }
        .order-summary strong {
            color: #111111;
        }
        h3 {
            font-size: 16px;
            margin: 0 0 8px 0;
            color: #111111;
        }
        .section-title {
            font-size: 18px;
            font-weight: bold;
            margin: 32px 0 16px;
            color: #111111;
        }
        .product-row {
            display: flex;
            align-items: flex-start;
            padding: 16px 0;
            border-bottom: 1px solid #e5e5e5;
        }
        .product-image {
            flex: 0 0 96px;
            width: 96px;
            height: 96px;
            background-color: #f0f0f0;
            margin-right: 16px;
            text-align: center;
            line-height: 96px;
            color: #999999;
            font-size: 12px;
        }
        .product-image img {
            max-width: 100%;
            max-height: 100%;
        }
        .product-info {
            flex: 1 1 auto;
        }
        .product-name {
            font-size: 16px;
            font-weight: bold;
            margin-bottom: 4px;
            color: #111111;
        }
        .product-meta {
            font-size: 14px;
            color: #555555;
        }
        .product-price {
            flex: 0 0 auto;
            font-size: 16px;
            font-weight: bold;
            color: #111111;
            align-self: center;
        }
        .meta-totals-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 24px;
        }
        .meta-totals-table > tbody > tr > td {
            vertical-align: top;
            padding: 8px 0;
        }
        .meta-col {
            width: 50%;
            padding-right: 24px !important;
        }
        .totals-col {
            width: 50%;
        }
        .meta-block {
            margin-bottom: 16px;
        }
        .meta-block .meta-label {
            font-size: 18px;
            font-weight: bold;
            color: #111111;
            margin: 0 0 4px 0;
        }
        .meta-block .meta-value {
            font-size: 14px;
            color: #555555;
            margin: 0;
        }
        .totals-table {
            width: 100%;
            border-collapse: collapse;
        }
        .totals-table td {
            padding: 6px 0;
            font-size: 14px;
            color: #555555;
        }
        .totals-table .label-col {
            text-align: left;
        }
        .totals-table .amount-col {
            text-align: right;
            color: #111111;
        }
        .totals-table tr.total td {
            font-size: 18px;
            font-weight: bold;
            color: #111111;
            padding-top: 12px;
        }
        .addresses-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 16px;
        }
        .addresses-table th {
            text-align: left;
            font-size: 16px;
            font-weight: bold;
            padding: 8px 0;
            color: #111111;
        }
        .addresses-table td {
            vertical-align: top;
            font-size: 14px;
            line-height: 1.5;
            color: #333333;
            padding: 4px 12px 0 0;
            width: 50%;
        }
        .note-block {
            margin: 24px 0;
            font-size: 14px;
            color: #333333;
        }
        .footer {
            background-color: #6cb409;
            color: #ffffff;
            text-align: center;
            padding: 32px 20px;
            margin-top: 32px;
        }
        .footer .tagline {
            font-size: 18px;
            font-weight: bold;
            letter-spacing: 1px;
            margin-bottom: 20px;
        }
        .social {
            margin: 16px 0;
        }
        .social a {
            display: inline-block;
            margin: 0 6px;
            text-decoration: none;
        }
        .social a img {
            display: block;
            width: 36px;
            height: 36px;
            border: 0;
        }
        .footer .sent-by {
            font-size: 14px;
            margin-top: 16px;
        }
        .footer .contact {
            font-size: 13px;
            margin-top: 4px;
            opacity: 0.95;
        }
        a:link {
            color: green;
            background-color: transparent;
            text-decoration: none;
        }
        a:visited {
            color: pink;
            background-color: transparent;
            text-decoration: none;
        }
        a:hover {
            color: red;
            background-color: transparent;
            text-decoration: underline;
        }
        a:active {
            color: yellow;
            background-color: transparent;
            text-decoration: underline;
        }
    </style>
</head>
<body>
    @php
        $customer = $order->user;
        $customerName = $customer?->name ?? 'Cliente';
        $billingAddress = $order->order_meta['address'] ?? [];
        $shippingAddress = $order->order_meta['address'] ?? [];
        $payment = $order->payments->first();
        $paymentMethodLabel = $payment?->paymentMethod?->name ?? 'No especificado';
        $shippingMethodLabel = ($order->shipping_cost > 0) ? 'Despacho a domicilio' : 'Retiro en tienda';
        // Every amount shown to the customer already includes VAT: the order total
        // holds the subtotal plus its VAT, and each line total does the same.
        $subtotal = (int) $order->total;
        $shippingCost = (int) $order->shipping_cost;
        $discount = 0;
        $refund = 0;
        $total = (int) $order->amount;
        $orderDate = optional($order->created_at)->format('d/m/Y H:i');
        $logoUrl = \Illuminate\Support\Facades\Storage::disk('s3')->url('assets/logo.png');
        $iconBase = \Illuminate\Support\Facades\Storage::disk('s3')->url('assets/icons');
        $fromAddress = config('mail.from.address');
    @endphp

    <div class="container">
        <div class="header">
            <img src="{{ $logoUrl }}" alt="Socomarca Compra Rápida" />
        </div>
        <div class="divider"></div>

        <div class="cart-icon-wrapper">
            <img class="cart-icon" src="{{ $iconBase }}/cart.png" width="90" height="90" alt="" />
        </div>

        <p class="intro">
            ¡Gracias por tu compra, <strong>{{ $customerName }}</strong>! Hemos recibido tu pedido y ya lo estamos preparando.
        </p>

        <p class="order-summary">
            Pedido <strong>N&deg;{{ $order->id }}</strong> - Total <strong>${{ number_format($total, 0, ',', '.') }}</strong>
            @if ($orderDate) - {{ $orderDate }} @endif
        </p>

        <h3 class="section-title">Detalle de la compra</h3>

        @foreach ($order->orderDetails as $item)
            @php
                $product = $item->product;
                $imageUrl = $product?->image_url
                    ?? $product?->image
                    ?? $product?->thumbnail
                    ?? null;
                $lineTotal = (int) round($item->total);
            @endphp
            <div class="product-row">
                <div class="product-image">
                    @if (! empty($imageUrl))
                        <img src="{{ \Illuminate\Support\Facades\Storage::url($imageUrl) }}" alt="{{ $product?->name ?? 'Producto' }}" />
                    @else
                        Producto
                    @endif
                </div>
                <div class="product-info">
                    <div class="product-name">{{ $product?->name ?? 'Producto' }}</div>
                    <div class="product-meta">Cantidad: {{ $item->quantity }}{{ $item->unit ? ' ' . $item->unit : '' }}</div>
                </div>
                <div class="product-price">${{ number_format($lineTotal, 0, ',', '.') }}</div>
            </div>
        @endforeach

        <table class="meta-totals-table" cellpadding="0" cellspacing="0">
            <tr>
                <td class="meta-col">
                    <div class="meta-block">
                        <p class="meta-label">M&eacute;todo de pago</p>
                        <p class="meta-value">{{ $paymentMethodLabel }}</p>
                    </div>
                    <div class="meta-block">
                        <p class="meta-label">Forma de env&iacute;o</p>
                        <p class="meta-value">{{ $shippingMethodLabel }}</p>
                    </div>
                    @if (! empty($order->notes))
                        <div class="meta-block">
                            <p class="meta-label">Nota</p>
                            <p class="meta-value">{{ $order->notes }}</p>
                        </div>
                    @endif
                </td>
                <td class="totals-col">
                    <table class="totals-table" cellpadding="0" cellspacing="0">
                        <tr>
                            <td class="label-col">Subtotal</td>
                            <td class="amount-col">${{ number_format($subtotal, 0, ',', '.') }}</td>
                        </tr>
                        <tr>
                            <td class="label-col">Descuento</td>
                            <td class="amount-col">-${{ number_format($discount, 0, ',', '.') }}</td>
                        </tr>
                        <tr>
                            <td class="label-col">Env&iacute;o</td>
                            <td class="amount-col">${{ number_format($shippingCost, 0, ',', '.') }}</td>
                        </tr>
                        <tr>
                            <td class="label-col">Pedido reembolsado</td>
                            <td class="amount-col">-${{ number_format($refund, 0, ',', '.') }}</td>
                        </tr>
                        <tr>
                            <td class="label-col">Reembolso</td>
                            <td class="amount-col">-$0</td>
                        </tr>
                        <tr class="total">
                            <td class="label-col">Total</td>
                            <td class="amount-col">${{ number_format($total, 0, ',', '.') }}</td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>

        <table class="addresses-table" cellpadding="0" cellspacing="0">
            <tr>
                <th>Direcci&oacute;n de facturaci&oacute;n</th>
                <th>Direcci&oacute;n de env&iacute;o</th>
            </tr>
            <tr>
                <td>
                    {{ $customerName }}<br />
                    @if (! empty($billingAddress['address_line1']))
                        {{ $billingAddress['address_line1'] }}@if (! empty($billingAddress['address_line2'])), {{ $billingAddress['address_line2'] }}@endif<br />
                    @endif
                    @if (! empty($billingAddress['postal_code'])) {{ $billingAddress['postal_code'] }}<br /> @endif
                    @if (! empty($customer?->phone)) {{ $customer->phone }}<br /> @endif
                    {{ $customer?->email }}
                </td>
                <td>
                    {{ $customerName }}<br />
                    @if (! empty($shippingAddress['address_line1']))
                        {{ $shippingAddress['address_line1'] }}@if (! empty($shippingAddress['address_line2'])), {{ $shippingAddress['address_line2'] }}@endif<br />
                    @endif
                    @if (! empty($shippingAddress['postal_code'])) {{ $shippingAddress['postal_code'] }}<br /> @endif
                    @if (! empty($customer?->phone)) {{ $customer->phone }}<br /> @endif
                    {{ $customer?->email }}
                </td>
            </tr>
        </table>

        <div class="footer">
            <div class="tagline">Socomarca Compra Rápida</div>
            <div class="social">
                <a href="#" aria-label="Facebook"><img src="{{ $iconBase }}/facebook.png" width="36" height="36" alt="Facebook" /></a>
                <a href="#" aria-label="Twitter"><img src="{{ $iconBase }}/twitter.png" width="36" height="36" alt="Twitter" /></a>
                <a href="#" aria-label="Instagram"><img src="{{ $iconBase }}/instagram.png" width="36" height="36" alt="Instagram" /></a>
                <a href="#" aria-label="YouTube"><img src="{{ $iconBase }}/youtube.png" width="36" height="36" alt="YouTube" /></a>
                <a href="#" aria-label="Pinterest"><img src="{{ $iconBase }}/pinterest.png" width="36" height="36" alt="Pinterest" /></a>
            </div>
            <div class="sent-by">Este correo fue enviado por: <a href="mailto:{{ $fromAddress }}">{{ $fromAddress }}</a></div>
            <div class="contact">Por cualquier duda comunicarse a <a href="mailto:{{ $fromAddress }}">{{ $fromAddress }}</a></div>
        </div>
    </div>
</body>
</html>
