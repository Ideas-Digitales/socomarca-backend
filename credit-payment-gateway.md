## Integración de la línea de crédito como método de pago (frontend)
1. GET /api/users/{user}/credit-lines?by_branch=1 -> Obtiene las líneas de crédito
2. GET /api/payment-methods -> Obtiene métodos de pago
3. POST /api/orders/pay -> Solicita el pago de la orden con el crédito
4. Renderiza resultado en UI

## Notas
### GET /api/users/{user}/credit-lines?by_branch=1
**response**
```jsonc
{
  "data": [
    {
      "KOEN": "76057232",
      "SUEN": "L01",
      "CRSD": 47707007999999.99,
      "CRSDVU": 5940894,
      "CRSDVV": 1115408,
      "CRSDCU": 0,
      "CRSDCV": 0
    },
    {
      "KOEN": "76057232",
      "SUEN": "L02",
      "CRSD": 27829087999999.996,
      "CRSDVU": 12162352,
      "CRSDVV": 2985284,
      "CRSDCU": 128424,
      "CRSDCV": 0
    }
  ]
}
```

### POST /api/orders/pay
**payload**
```jsonc
{
  "address_id": 34, // Dirección seleccionada para la orden
  "payment_method_id": 2, // Método de pago seleccionado
  "payment_data": {
    "SUEN": "L01",
  }
}
```
**response**
```jsonc
{
  "success": true,
  "message": "Pago exitoso",
  "data": {
    // Por definir
  }
}
```