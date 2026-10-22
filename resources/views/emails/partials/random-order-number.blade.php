@if ($folio = $order->randomDocumentFolio())
    <p class="{{ $class ?? 'detail' }}">Número de pedido Random: <strong>{{ $folio }}</strong></p>
@endif
