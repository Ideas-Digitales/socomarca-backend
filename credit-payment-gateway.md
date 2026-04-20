## Integración de la línea de crédito como método de pago (frontend)

1. GET /api/users/{user}/credit-lines-> Obtiene valores de crédito
2. GET /api/payment-methods -> Obtiene métodos de pago
3. POST /api/orders/pay -> Solicita el pago de la orden con el crédito
4. Renderiza resultado en UI

## Notas

### GET /api/users/{user}/credit-lines
 **response** 

```jsonc
{
    "CRSD": 47707007999999.99,
    "CRSDVU": 5940894,
    "CRSDVV": 1115408,
    "CRSDCU": 0,
    "CRSDCV": 0
}
```

### POST /api/orders/pay

 **payload** 

```jsonc
{
    "address_id": 34, // Dirección seleccionada para la orden
    "payment_method": "random_credit", // Método de pago seleccionado
}
```

 **response** 

```jsonc
// Successful response
{
    "success": true,
    "message": "Pago exitoso",
    "data": {
        "transaction": {
            "status": "AUTHORIZED", // AUTHORIZED | FAILED
        },
        "payment": {
            "auth_code", "amount",
            "response_status",
            "response_message",
            "token": "", // transaction token | null
            "paid_at": "2024-05-20T14:30:00Z", // datetime | null
            "payment_method": {},
            "order": {},
        }, // payment object | null
        "credit_status": {
            "CRSD": 47707007999999.99,
            "CRSDVU": 5940894,
            "CRSDVV": 1115408,
            "CRSDCU": 0,
            "CRSDCV": 0
        }
    }
}
```

```jsonc
// Failed payment
{
    "success": false,
    "message": "Pago fallido",
    "data": {
        "transaction": {
            "status": "FAILED", // AUTHORIZED | FAILED
        },
        "payment": null, // payment object | null
        "credit_status": {
            "CRSD": 47707007999999.99,
            "CRSDVU": 5940894,
            "CRSDVV": 1115408,
            "CRSDCU": 0,
            "CRSDCV": 0
        }
    }
}
```

En caso de que el método de pago "Crédito de Random" falle, se retornará
un error 500
