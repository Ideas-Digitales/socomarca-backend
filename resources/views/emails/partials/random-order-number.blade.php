@if ($number = $order->randomOrderNumber())
    <p class="{{ $class ?? 'detail' }}">Número de pedido Random: <strong>{{ $number }}</strong></p>
@endif
