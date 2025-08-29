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

## Development Commands

### Container Access
Get into php container
```bash
docker compose exec -it workcontainer bash
```

### Development Workflow
```bash
# Start all development services (server, queue, logs, vite)
composer dev

# Start queue worker
docker compose exec workcontainer php artisan queue:work

# Code quality and linting
docker compose exec workcontainer ./vendor/bin/pint
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
## Individual syncs

Sync categories
```bash
docker compose exec workcontainer php artisan random:sync-categories
```

Sync brands
```bash
docker compose exec workcontainer php artisan random:sync-brands
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

Sync users
```bash
docker compose exec workcontainer php artisan random:sync-users
```

### Email Testing
```bash
# Test email sending functionality
docker compose exec workcontainer php artisan app:test-email-sending {email-address}
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

# Testing Random ERP Sync

```bash
# Ejecutar test básico
docker compose exec workcontainer php artisan test tests/Feature/SyncProductTest.php --env=testing --filter="el job de sincronización procesa productos correctamente" 
```

### Probar rendimiento con muchos productos:
```bash
# Test de volumen
docker compose exec workcontainer php artisan test tests/Feature/SyncProductIntegrationTest.php --env=testing --filter="sincronización con gran volumen de datos" 
```

### Verificar logs y monitoreo:
```bash
# Test de logs
docker compose exec workcontainer php artisan test tests/Feature/SyncProductMonitoringTest.php --env=testing --filter="registra logs correctos" 
```

## Available Artisan Commands

### ERP Synchronization Commands
- **`random:sync-all`**: Executes complete ERP synchronization in chain (categories → brands → products → prices → stock → users)
- **`random:sync-categories`**: Synchronizes categories and subcategories from Random ERP
- **`random:sync-brands`**: Synchronizes brands from Random ERP
- **`random:sync-products`**: Synchronizes products from Random ERP
- **`random:sync-prices`**: Synchronizes product prices with multi-unit support from Random ERP
- **`random:sync-stock`**: Updates product stock levels from Random ERP
- **`random:sync-users`**: Synchronizes users/customers from Random ERP

### Utility Commands
- **`app:test-email-sending {email-address}`**: Tests email functionality by sending a test email

## Background Jobs (Queue System)

### ERP Synchronization Jobs
- **`SyncRandomCategories`**: Processes categories from Random API, handles 3-level hierarchy (categories/subcategories)
- **`SyncRandomBrands`**: Synchronizes brands from Random API using MRPR and NOKOMR fields
- **`SyncRandomProducts`**: Creates/updates products with category associations and brand information
- **`SyncRandomPrices`**: Manages complex pricing with multiple units per product
- **`SyncRandomStock`**: Updates stock levels for products across different units
- **`SyncRandomUsers`**: Creates/updates customer accounts from ERP data with RUT validation

### Image Processing Jobs
- **`SyncProductImage`**: Main job that processes ZIP files containing product images and Excel metadata
- **`UploadImagesChunk`**: Handles chunked upload of images to S3 storage
- **`ProcessProductImageChunkFromS3`**: Processes image chunks from S3, updates product records
- **`CleanupChunkTempS3`**: Cleans up temporary S3 files after processing

### Email Jobs
- **`SendRawTestEmail`**: Handles raw email sending for testing purposes

### Queue Configuration
- ERP sync jobs use dedicated queues: `random-categories`, `random-brands`, `random-products`, `random-prices`, `random-stock`, `random-users`
- Image processing jobs use default queue with chaining for sequential processing
- All jobs implement proper error handling and logging

### Queue Workers
- **`php artisan queue:work`**: Starts the queue worker for ERP sync jobs
- **`php artisan queue:work --queue=random-categories`**: Starts the queue worker for categories sync
- **`php artisan queue:work --queue=random-brands`**: Starts the queue worker for brands sync
- **`php artisan queue:work --queue=random-products`**: Starts the queue worker for products sync
- **`php artisan queue:work --queue=random-prices`**: Starts the queue worker for prices sync
- **`php artisan queue:work --queue=random-stock`**: Starts the queue worker for stock sync
- **`php artisan queue:work --queue=random-users`**: Starts the queue worker for users sync

### Job Chaining Examples
```php
// ERP sync chain (executed by random:sync-all)
Bus::chain([
    new SyncRandomCategories(),
    new SyncRandomBrands(),
    new SyncRandomProducts(),
    new SyncRandomPrices(),
    new SyncRandomStock(),
    new SyncRandomUsers(),
])->dispatch();

// Image processing chain (per chunk)
Bus::dispatchChain([
    new ProcessProductImageChunkFromS3($chunk, $tmpPrefix),
    new CleanupChunkTempS3($files, $tmpPrefix),
]);
```

# LocalStack S3

### Crear el bucket

Instalar awscli2
```bash
curl "https://awscli.amazonaws.com/awscli-exe-linux-x86_64.zip" -o "awscliv2.zip"
unzip awscliv2.zip
sudo ./aws/install
```

Configurar las variables de entorno
```bash
export AWS_ACCESS_KEY_ID=test
export AWS_SECRET_ACCESS_KEY=test
export AWS_DEFAULT_REGION=us-east-1
```

con el comando aws crea el bucket
```bash
aws --endpoint-url=http://localhost:4566/ s3 mb s3://socomarca-bucket
```
listar los bucket
```bash
aws --endpoint-url=http://localhost:4566/ s3 ls
```
listar el contenido 
```bash
aws --endpoint-url=http://localhost:4566/ s3 ls s3://socomarca-bucket/products/
```

