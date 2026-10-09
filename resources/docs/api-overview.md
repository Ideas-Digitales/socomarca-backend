Backend of Socomarca's quick purchase platform (B2B ordering). Products, prices, customers and their
branches are synced from Random ERP; orders are sent back to Random ERP as sales documents.

## Authentication

1. Request a token with `POST /auth/token`, sending the user's email and password.
2. Send the token in every request: `Authorization: Bearer {token}`.

Requests without a valid token get `401`. Tokens of a user stop working as soon as the user is deactivated.

## Authorization

Access is granted by role permissions (roles: `superadmin`, `admin`, `supervisor`, `editor` and
`customer`). Each endpoint states the permission it requires; without it the API responds `403` with
`{"message": "You do not have permission."}`.

## Customers and branches

- Every Random ERP entity branch (entity code `user_code` + branch code `branch_code`) is a user with
  the `customer` role. `branch_type` tells whether it is the primary (`P`) or a secondary (`S`) branch.
- A primary branch can place orders for itself and for the active secondary branches of its entity:
  `GET /branches` lists them and `POST /orders/pay` receives the chosen one as `customer_id`. A secondary
  branch can only order for itself.
- The user who places an order always pays and is billed: catalog, cart and order use its price lists,
  credit payments use its credit line and the Random ERP sales note is issued for its branch. The branch
  in `customer_id` is the shipping branch: the order ships to one of its addresses.
- Customers log in with their commercial email (Random ERP `EMAILCOMER`). Their data is managed by the
  sync, so the administration can only activate or deactivate them.

## Conventions

- Requests and responses are JSON.
- Paginated lists accept `page` and `per_page`, and respond with `data`, `links` and `meta`.
- Validation errors respond `422` with `message` and an `errors` object keyed by field.
- Amounts are in Chilean pesos (CLP).
