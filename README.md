# Socomarca Backend API

Laravel 12 backend API for Socomarca, an e-commerce platform with **multi-warehouse inventory management system**.

## Features

- **Multi-warehouse inventory management system with stock reservations**
- Docker-based development environment with PostgreSQL, Nginx, MeiliSearch, and pgAdmin
- Integration with Random ERP for product and warehouse synchronization
- Laravel Sanctum for authentication
- Spatie Laravel Permission for role-based access control
- MeiliSearch for product search functionality
- Transbank SDK for payment processing via WebPay
- Pest testing framework with comprehensive feature tests
- Chilean geography integration (regions/municipalities)
- RUT validation system
- GitHub Actions CI/CD for QA environment deployment
- Push notifications via FCM (Firebase Cloud Messaging)
- **Automated cart reservation expiration system**

## Setup

### Environment Configuration
```bash
cp .env.example .env

# Configure cart reservation timeout (optional - default: 1440 minutes = 24 hours)
# Add to .env file: CART_RESERVATION_TIMEOUT=1440
```

### Docker Setup
```bash
# Build docker composition
docker compose build --build-arg USER_ID=$(id -u) --build-arg GROUP_ID=$(id -g) --no-cache

# Start docker composition (services: workcontainer, web, db, meilisearch, pgadmin)
docker compose up -d

# Install composer dependencies
docker compose exec workcontainer composer install

# Generate app encryption key
docker compose exec workcontainer php artisan key:generate

# Run migrations and seeders
docker compose exec workcontainer php artisan migrate:fresh --seed

#Complete database reset and sync all data
docker compose exec workcontainer php artisan db:wipe && docker compose exec workcontainer php artisan migrate && docker compose exec workcontainer php artisan random:sync-all
```

## Development Commands

### Development Workflow
```bash
# Start all development services (server, queue, logs, vite)
composer dev

# Access PHP container
docker compose exec -it workcontainer bash

# Start queue worker (with all ERP sync queues)
docker compose exec workcontainer php artisan queue:work --queue=random-warehouses,random-categories,random-brands,random-products,random-prices,random-stock,random-users,default

# Code quality and linting
docker compose exec workcontainer ./vendor/bin/pint
```

## ERP & Warehouse Synchronization

*Order is important: warehouses → categories → products → stock*

### Complete Synchronization
```bash
# Run all syncs (includes warehouses, categories, brands, products, prices, stock, users)
docker compose exec workcontainer php artisan random:sync-all
```

### Individual Synchronizations
```bash
# Sync warehouses from Random ERP
docker compose exec workcontainer php artisan random:sync-warehouses

# Sync categories and subcategories
docker compose exec workcontainer php artisan random:sync-categories

# Sync brands
docker compose exec workcontainer php artisan random:sync-brands

# Sync products
docker compose exec workcontainer php artisan random:sync-products

# Sync prices with multi-unit support
docker compose exec workcontainer php artisan random:sync-prices

# Sync stock across multiple warehouses
docker compose exec workcontainer php artisan random:sync-stock

# Sync users/customers
docker compose exec workcontainer php artisan random:sync-users
```

## Warehouse & Stock Management

### Stock Reservation Management
```bash
# Release expired cart reservations (dry-run mode for testing)
docker compose exec workcontainer php artisan reservations:release-expired --dry-run

# Release expired cart reservations (actual execution)
docker compose exec workcontainer php artisan reservations:release-expired

# Check scheduled tasks (including hourly reservation cleanup)
docker compose exec workcontainer php artisan schedule:list
```

## Testing

### Database Setup
```sql
CREATE DATABASE socomarca_backend_testing;
```

### Run Tests
```bash
# Run migrations for testing
php artisan migrate --env=testing

# Run all tests
php artisan test --env=testing

# Run specific test file
docker compose exec workcontainer php artisan test tests/Feature/CartItemTest.php

# Run specific test with filter
docker compose exec workcontainer ./vendor/bin/pest tests/Feature/CartItemTest.php --filter="puede agregar un item al carrito"

# Run ERP sync integration tests
docker compose exec workcontainer php artisan test tests/Feature/SyncProductTest.php --env=testing --filter="el job de sincronización procesa productos correctamente"

# Test performance with large datasets
docker compose exec workcontainer php artisan test tests/Feature/SyncProductIntegrationTest.php --env=testing --filter="sincronización con gran volumen de datos"
```

### Email Testing
```bash
# Test email sending functionality
docker compose exec workcontainer php artisan app:test-email-sending {email-address}
```

## Available Artisan Commands

### ERP Synchronization Commands
- **`random:sync-all`**: Executes complete ERP synchronization in chain (warehouses → categories → brands → products → prices → stock → users)
- **`random:sync-warehouses`**: Synchronizes warehouse information from Random ERP
- **`random:sync-categories`**: Synchronizes categories and subcategories from Random ERP
- **`random:sync-brands`**: Synchronizes brands from Random ERP
- **`random:sync-products`**: Synchronizes products from Random ERP
- **`random:sync-prices`**: Synchronizes product prices with multi-unit support from Random ERP
- **`random:sync-stock`**: Updates product stock levels across multiple warehouses from Random ERP
- **`random:sync-users`**: Synchronizes users/customers from Random ERP

### Warehouse & Stock Management Commands
- **`reservations:release-expired`**: Releases expired cart stock reservations (configurable timeout via CART_RESERVATION_TIMEOUT env var)

### Utility Commands
- **`app:test-email-sending {email-address}`**: Tests email functionality by sending a test email

## Background Jobs (Queue System)

### ERP Synchronization Jobs
- **`SyncRandomWarehouses`**: Synchronizes warehouse information from Random ERP, manages priority assignments
- **`SyncRandomCategories`**: Processes categories from Random API, handles 3-level hierarchy (categories/subcategories)
- **`SyncRandomBrands`**: Synchronizes brands from Random API using MRPR and NOKOMR fields
- **`SyncRandomProducts`**: Creates/updates products with category associations and brand information
- **`SyncRandomPrices`**: Manages complex pricing with multiple units per product
- **`SyncRandomStock`**: Updates stock levels for products across multiple warehouses and units
- **`SyncRandomUsers`**: Creates/updates customer accounts from ERP data with RUT validation

### Image Processing Jobs
- **`SyncProductImage`**: Main job that processes ZIP files containing product images and Excel metadata
- **`UploadImagesChunk`**: Handles chunked upload of images to S3 storage
- **`ProcessProductImageChunkFromS3`**: Processes image chunks from S3, updates product records
- **`CleanupChunkTempS3`**: Cleans up temporary S3 files after processing

### Notification Jobs
- **`SendBulkNotification`**: Sends bulk email notifications to all customers using queued mail
- **`SendPushNotification`**: Sends push notifications to all users with active FCM tokens

### Email Jobs
- **`SendRawTestEmail`**: Handles raw email sending for testing purposes

### Queue Configuration
- ERP sync jobs use dedicated queues: `random-warehouses`, `random-categories`, `random-brands`, `random-products`, `random-prices`, `random-stock`, `random-users`
- Image processing jobs use default queue with chaining for sequential processing
- Notification jobs use Laravel's queue system for scalability
- All jobs implement proper error handling and logging

### Job Chaining Examples
```php
// ERP sync chain (executed by random:sync-all)
Bus::chain([
    new SyncRandomWarehouses(),    // First sync warehouses
    new SyncRandomCategories(),
    new SyncRandomBrands(),
    new SyncRandomProducts(),
    new SyncRandomPrices(),
    new SyncRandomStock(),         // Now uses synchronized warehouses
    new SyncRandomUsers(),
])->dispatch();

// Image processing chain (per chunk)
Bus::dispatchChain([
    new ProcessProductImageChunkFromS3($chunk, $tmpPrefix),
    new CleanupChunkTempS3($files, $tmpPrefix),
]);
```

## Multi-Warehouse System Architecture

### Database Schema
- **`warehouses`**: Stores warehouse information synced from Random ERP with priority system
- **`product_stocks`**: Junction table managing inventory per product-warehouse-unit with reservations
- **`cart_items`**: Extended with warehouse_id and reserved_at for stock reservations
- **`order_items`**: Extended with warehouse_id for fulfillment tracking

### Stock Reservation Flow
1. **Add to Cart**: Automatically reserves stock from highest priority warehouse with availability
2. **Stock Allocation**: Uses priority-based algorithm (priority 1 = default warehouse)
3. **Automatic Expiration**: Releases expired reservations via scheduled cleanup (configurable timeout)
4. **Order Completion**: Reduces actual stock and releases remaining reservations
5. **Order Failure**: Releases all reservations back to available stock

### Warehouse Management
- **Priority System**: Warehouses have configurable priorities (1 = highest/default)
- **Multi-Unit Support**: Stock tracked per product-warehouse-unit combination
- **Real-time Sync**: Warehouse information synchronized from Random ERP
- **API Management**: Full CRUD operations with permission-based access control

### Event-Driven Architecture
- **Events**: OrderCompleted, OrderFailed, CartItemRemoved
- **Listeners**: ReleaseReservedStock handles all stock liberation scenarios
- **Automatic Cleanup**: Scheduled task runs hourly to release expired cart reservations

### Configuration
```bash
# Environment variable for cart reservation timeout
CART_RESERVATION_TIMEOUT=1440  # minutes (default: 24 hours)
```

## API Endpoints

### Warehouse Routes
- `GET /api/warehouses` - List active warehouses ordered by priority
- `GET /api/warehouses/stock-summary` - Stock summary by warehouse
- `GET /api/warehouses/{warehouse}` - Specific warehouse details
- `PATCH /api/warehouses/{warehouse}/set-default` - Set warehouse as default (requires manage-warehouses permission)
- `GET /api/warehouses/{warehouse}/stock` - Product stock detail by warehouse

## Permissions

### Warehouse Permissions
- **`read-warehouses`**: View warehouse information (roles: superadmin, admin, supervisor, editor)
- **`manage-warehouses`**: Full warehouse management (roles: superadmin, admin)

## QA Environment

### QA Deployment
```bash
# QA environment uses separate compose file
docker compose -f compose.qa.yml up -d

# QA environment commands
docker compose -f compose.qa.yml exec app php artisan migrate:fresh --force --seed
docker compose -f compose.qa.yml exec app php artisan config:cache
docker compose -f compose.qa.yml exec app php artisan route:cache
docker compose -f compose.qa.yml exec app php artisan event:cache
```

### Deployment & CI/CD
- **Trigger**: Automatic on push to `main` branch
- **GitHub Actions**: Uses self-hosted runner with `laravel-qa-deploy` group
- **Process**: Updates code → maintenance mode → dependencies → migrations → caching → back online
- **Docker Compose**: Uses `compose.qa.yml` for QA-specific configuration
- **Directory**: QA deployment runs from `/home/ubuntu/deploys/socomarca-backend`

## LocalStack S3 Setup

### Install AWS CLI
```bash
curl "https://awscli.amazonaws.com/awscli-exe-linux-x86_64.zip" -o "awscliv2.zip"
unzip awscliv2.zip
sudo ./aws/install
```

### Configure Environment
```bash
export AWS_ACCESS_KEY_ID=test
export AWS_SECRET_ACCESS_KEY=test
export AWS_DEFAULT_REGION=us-east-1
```

### S3 Operations
```bash
# Create bucket
aws --endpoint-url=http://localhost:4566/ s3 mb s3://socomarca-bucket

# List buckets
aws --endpoint-url=http://localhost:4566/ s3 ls

# List bucket contents
aws --endpoint-url=http://localhost:4566/ s3 ls s3://socomarca-bucket/products/
```

## Development Services Access
- **Web Application**: http://localhost:8080
- **pgAdmin**: http://localhost:80
- **MeiliSearch**: http://localhost:7700

## Key Features

### Business Logic
- **Multi-Warehouse Inventory**: Products tracked across multiple warehouses with priority-based allocation
- **Stock Reservations**: Automatic stock reservation when items added to cart with configurable expiration
- **Cart Persistence**: Shopping cart stored in database with warehouse assignments
- **Order Workflow**: Complete order lifecycle from cart to payment with warehouse fulfillment tracking
- **ERP Synchronization**: Real-time product/price/warehouse/stock updates from external ERP
- **RUT Validation**: Chilean tax ID validation throughout the system
- **Multi-role Authorization**: Users can have multiple roles with granular permissions

### Scheduled Tasks
- **Stock Cleanup**: Hourly release of expired cart reservations
- **ERP Sync**: Daily synchronization of products and users
- **All tasks**: Run without overlapping to prevent conflicts

For more detailed information, see the [CLAUDE.md](CLAUDE.md) file.