## Setup

Setup environment configuration
```bash
cp .env.example .env
```

Build docker composition
```bash
docker compose build --build-arg USER_ID=$(id -u) --build-arg GROUP_ID=$(id -g) --no-cache
```

Start docker composition
```bash
docker compose up -d
```

Install composer dependencies
```bash
docker compose exec workcontainer composer install
```

Setup app encryption key
```bash
docker compose exec workcontainer php artisan key:generate
```

Run migrations and seeders
```bash
docker compose exec workcontainer php artisan migrate:fresh --seed
```

## Commands

Get into php container
```bash
docker compose exec -it workcontainer bash
```


## Sync products

*Order is important, categories must be synced before products.*

Start queue worker
```bash
docker compose exec workcontainer php artisan queue:work
```

Run all syncs
```bash
docker compose exec workcontainer php artisan random:sync-all
```
## Indivual syncs

Sync categories
```bash
docker compose exec workcontainer php artisan random:sync-categories
```

Sync products
```bash
docker compose exec workcontainer php artisan random:sync-products
```

Sync prices
```bash
docker compose exec workcontainer php artisan random:sync-prices
```

Sync stock
```bash
docker compose exec workcontainer php artisan random:sync-stock
```



# Testing

First you must create a testing DB
```sql
CREATE DATABASE socomarca_backend_testing;
```

Then run the migrations in the testing database
```bash
php artisan migrate --env=testing
```

Finally you will be able to run all the tests
```bash
php artisan test --env=testing
```

Run a specific test
```bash
docker compose exec workcontainer php artisan test tests/Feature/CartItemTest.php
```

Run a specific test with a specific filter
```bash
docker compose exec workcontainer ./vendor/bin/pest tests/Feature/CartItemTest.php --filter="puede agregar un item al carrito"
```




