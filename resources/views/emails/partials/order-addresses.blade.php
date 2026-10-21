@php
    $addresses = $order->notificationAddresses();
    $billing = $addresses['billing'];
    $shipping = $addresses['shipping'];
@endphp
<table class="addresses-table" cellpadding="0" cellspacing="0">
    <tr>
        <th>Direcci&oacute;n de facturaci&oacute;n</th>
        <th>Direcci&oacute;n de env&iacute;o</th>
    </tr>
    <tr>
        <td>
            {{ $billing['name'] }}<br />
            @if (! empty($billing['address']['address_line1']))
                {{ $billing['address']['address_line1'] }}@if (! empty($billing['address']['address_line2'])), {{ $billing['address']['address_line2'] }}@endif<br />
            @endif
            @if (! empty($billing['address']['postal_code'])) {{ $billing['address']['postal_code'] }}<br /> @endif
            @if (! empty($billing['phone'])) {{ $billing['phone'] }}<br /> @endif
            {{ $billing['email'] }}
        </td>
        <td>
            {{ $shipping['name'] }}<br />
            @if (! empty($shipping['address']['address_line1']))
                {{ $shipping['address']['address_line1'] }}@if (! empty($shipping['address']['address_line2'])), {{ $shipping['address']['address_line2'] }}@endif<br />
            @endif
            @if (! empty($shipping['address']['postal_code'])) {{ $shipping['address']['postal_code'] }}<br /> @endif
            @if (! empty($shipping['phone'])) {{ $shipping['phone'] }}<br /> @endif
            {{ $shipping['email'] }}
        </td>
    </tr>
</table>
