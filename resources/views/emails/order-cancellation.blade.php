<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Pedido cancelado</title>
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
        .intro {
            font-size: 18px;
            line-height: 1.5;
            margin: 24px 0 12px;
        }
        .detail {
            font-size: 16px;
            line-height: 1.5;
            margin: 8px 0;
        }
        .section-title {
            font-size: 18px;
            font-weight: bold;
            margin: 32px 0 16px;
            color: #111111;
        }
        .product-row {
            padding: 12px 0;
            border-bottom: 1px solid #e5e5e5;
        }
        .footer {
            margin-top: 40px;
            text-align: center;
            color: #666666;
            font-size: 14px;
        }
    </style>
</head>
<body>
    @php
        $buyer = $order->user;
        $buyerName = $buyer?->name ?? 'Cliente';
        $customer = $order->customer ?? $buyer;
        $customerName = $customer?->name ?? $buyerName;
        $forAnotherBranch = $buyer && $customer && ! $customer->is($buyer);
        $meta = $order->order_meta;
        $contact = is_array($meta) ? ($meta['contact'] ?? null) : null;
        $contactName = is_array($contact) ? ($contact['name'] ?? null) : null;
        $total = (int) $order->amount;
        $logoUrl = config('random.site_logo_url');
        $fromAddress = config('mail.from.address');
    @endphp

    <div class="container">
        <div class="header">
            @if ($logoUrl)
                <img src="{{ $logoUrl }}" alt="Socomarca Compra Rápida" />
            @endif
        </div>
        <div class="divider"></div>

        <p class="intro">
            El pedido <strong>N&deg;{{ $order->id }}</strong> de <strong>{{ $buyerName }}</strong> fue cancelado.
        </p>

        @if ($forAnotherBranch)
            <p class="detail">La compra era para la sucursal <strong>{{ $customerName }}</strong>.</p>
        @endif

        @if (is_string($contactName) && trim($contactName) !== '')
            <p class="detail">El contacto del pedido es <strong>{{ $contactName }}</strong>.</p>
        @endif

        <p class="detail">Total del pedido: <strong>${{ number_format($total, 0, ',', '.') }}</strong></p>

        @if ($order->orderDetails->isNotEmpty())
            <h3 class="section-title">Detalle de la compra</h3>
            @foreach ($order->orderDetails as $item)
                <div class="product-row">
                    {{ $item->product?->name ?? 'Producto' }}
                    — Cantidad: {{ $item->quantity }}{{ $item->unit ? ' '.$item->unit : '' }}
                </div>
            @endforeach
        @endif

        <div class="footer">
            Socomarca Compra Rápida<br />
            Este correo fue enviado por {{ $fromAddress }}
        </div>
    </div>
</body>
</html>
