<?php

use App\Http\Controllers\Api\AddressController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\SubcategoryController;
use App\Http\Controllers\Api\BrandController;
use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\PriceController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\Api\FavoriteController;
use App\Http\Controllers\Api\FavoriteListController;
use App\Http\Controllers\Api\CartItemController;
use App\Http\Controllers\Api\PaymentMethodController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\SiteinfoController;
use App\Http\Controllers\Api\WebpayController;
use App\Http\Controllers\Api\FaqController;
use App\Http\Controllers\SettingsController;

Route::prefix('auth')->group(function () {
    Route::post('/token', [AuthController::class, 'login'])->name('auth.token.store');
    Route::middleware('throttle:6,1')->group(function () {
        Route::post('/restore', [PasswordResetController::class, 'forgotPassword'])->name('auth.password.restore');
    });
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/check-token', function () {
            return response()->json(['valid' => true]);
        })->name('auth.check.token');
        Route::delete('/token', [AuthController::class, 'destroy'])->name('auth.token.destroy');
        Route::prefix('/password')->group(function () {
            Route::put('', [PasswordResetController::class, 'changePassword'])->name('password.update');
            Route::get('/status', [PasswordResetController::class, 'checkPasswordStatus'])->name('password.status');
        });
    });
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/profile', [UserController::class, 'profile']);


    Route::get('/users/exports', [UserController::class, 'export'])->middleware('role:admin|superadmin|supervisor');
    Route::get('/users/customers', [UserController::class, 'customersList']);
    Route::post('/users/search', [UserController::class, 'search'])->middleware('permission:manage-users');

    Route::resource('/users', UserController::class)
        ->only(['index', 'store', 'show', 'update', 'destroy'])
        ->middlewareFor('index', 'can:viewAny,App\Models\User')
        ->middlewareFor('store', 'can:create,App\Models\User')
        ->middlewareFor('show', 'can:view,user')
        ->middlewareFor('update', 'can:update,user')
        ->middlewareFor('destroy', 'can:delete,user');

//    Route::get('/users', [UserController::class, 'index'])->middleware('permission:manage-users');
//    Route::post('/users', [UserController::class, 'store'])
//        ->middleware('permission:manage-users')
//        ->name('users.store');
//    Route::get('/users/{id}', [UserController::class, 'show'])->middleware('permission:manage-users');
//    Route::match(['put', 'patch'], '/users/{user}', [UserController::class, 'update'])
//        ->middleware('permission:manage-users')->name('users.update');
//    Route::delete('/users/{id}', [UserController::class, 'destroy'])->middleware('permission:manage-users');

    Route::resource('permissions', \App\Http\Controllers\Api\PermissionController::class, ['only' => 'index'])
        ->middleware('permission:see-all-permissions');

    Route::middleware(['role:admin|superadmin'])->group(function () {
        Route::get('/roles', [RoleController::class, 'index']);
        Route::get('/roles/users', [RoleController::class, 'rolesWithUsers']);
        Route::get('/roles/{user}', [RoleController::class, 'userRoles']);
    });

    Route::resource('addresses', AddressController::class)
        ->only(['index', 'store', 'show', 'update', 'destroy'])
        ->parameters(['addresses' => 'address'])
        ->middlewareFor('index', 'can:viewAny,App\Models\Address')
        ->middlewareFor('store', 'can:create,App\Models\Address')
        ->middlewareFor('show', 'can:view,address')
        ->middlewareFor('update', 'can:update,address')
        ->middlewareFor('destroy', 'can:delete,address');

    Route::get('/regions', [AddressController::class, 'regions'])->name('addresses.regions');
    Route::get('/regions/{regionId?}', [AddressController::class, 'municipalities'])->name('addresses.municipalities');

    Route::middleware(['auth:sanctum', 'permission:see-all-reports'])->group(function () {
        Route::patch('/regions/{region}/municipalities/status', [AddressController::class, 'updateRegionMunicipalitiesStatus'])->name('addresses.regions.municipalities.status');
        Route::patch('/municipalities/status', [AddressController::class, 'updateMunicipalitiesStatus'])->name('addresses.municipalities.bulk-status');
    });
    
    
    Route::get('/categories/exports', [CategoryController::class, 'export'])
        ->middleware('permission:read-all-reports')->name('categories.export');
    Route::resource('categories', CategoryController::class)
        ->only(['index', 'show'])
        ->middleware('permission:read-all-categories');
    Route::post('/categories/search', [CategoryController::class, 'search'])
        ->middleware('permission:read-all-categories')->name('categories.search');

    
    Route::get('/subcategories', [SubcategoryController::class,'index'])->middleware('permission:read-all-subcategories')->name('subcategories.index');
    Route::get('/subcategories/{id}', [SubcategoryController::class,'show'])->middleware('permission:read-all-subcategories')->name('subcategories.show');

    Route::resource('subcategories', SubcategoryController::class)
    ->only(['index', 'show'])
    ->middlewareFor('index', 'permission:read-all-subcategories')
    ->middlewareFor('show', 'permission:read-all-subcategories');
    
    Route::get('/products/price-extremes', [ProductController::class, 'getPriceExtremes'])->name('products.price-extremes');
    Route::get('products', [ProductController::class, 'index']);
    Route::get('products/{id}', [ProductController::class, 'show']);
    Route::post('/products/search', [ProductController::class, 'search'])->name('products.search');

    Route::resource(
        'favorites-list', FavoriteListController::class,
        ['only' => ['index', 'store', 'show', 'update', 'destroy']]
    )
        ->parameters(['favorites-list' => 'favoriteList'])
        ->middlewareFor('index', 'can:viewAny,App\Models\FavoriteList')
        ->middlewareFor('store', 'can:create,App\Models\FavoriteList')
        ->middlewareFor('show', 'can:view,favoriteList')
        ->middlewareFor('update', 'can:update,favoriteList')
        ->middlewareFor('destroy', 'can:delete,favoriteList');

    Route::resource('favorites', FavoriteController::class, ['only' => ['index', 'store', 'destroy']])
        ->middlewareFor('index', 'can:viewAny,App\Models\Favorite')
        ->middlewareFor('store', 'can:create,App\Models\Favorite')
        ->middlewareFor('destroy', 'can:delete,favorite');

    Route::get('/cart', [CartController::class, 'index']);
    Route::delete('/cart', [CartItemController::class, 'emptyCart'])->name('cart.empty');
    Route::post('/cart/add-order', [CartController::class, 'addOrderToCart']);
    Route::post('/cart/items', [CartItemController::class, 'store']);
    Route::delete('/cart/items', [CartItemController::class, 'destroy']);

    Route::get('/payment-methods', [PaymentMethodController::class, 'index']);
    Route::put('/payment-methods/{id}', [PaymentMethodController::class, 'update']);

    Route::get('/brands', [BrandController::class, 'index'])->middleware(['permission:read-all-brands']);

    Route::apiResource('prices', PriceController::class)->only(['index']);

    // Rutas de orden
    Route::get('/orders', [OrderController::class, 'index']);
    Route::post('/orders/pay', [OrderController::class, 'payOrder']);


    Route::middleware(['auth:sanctum', 'role:admin|superadmin|supervisor'])->group(function () {

        Route::post('/orders/reports/transactions/export', [ReportController::class, 'export']);
        Route::post('/orders/reports/municipalities/export', [ReportController::class, 'exportTopMunicipalities']);
        Route::post('/orders/reports/products/export', [ReportController::class, 'exportTopProducts']);
        Route::post('/orders/reports/categories/export', [ReportController::class, 'exportTopCategories']);
        Route::post('/orders/reports/export', [ReportController::class, 'ordersReportExport']);

        Route::post('/orders/reports', [ReportController::class, 'report']);
        Route::post('/orders/reports/top-product-list', [ReportController::class, 'productsSalesList']);
        Route::post('/orders/reports/transactions-list', [ReportController::class, 'transactionsList']);
        Route::post('/orders/reports/clients-list', [ReportController::class, 'clientsList']);
        Route::post('/orders/reports/clients/export', [ReportController::class, 'clientsExport']);
        Route::post('/orders/reports/failed-transactions-list', [ReportController::class, 'failedTransactionsList']);
        Route::get('/orders/reports/transaction/{id}', [ReportController::class, 'transactionId']);

    });

});

// Rutas FAQs
Route::post('/faq/search', [FaqController::class, 'search'])->name('faq.search');

Route::resource('faq', FaqController::class)
    ->only(['index', 'store', 'show', 'update', 'destroy'])
    ->parameters(['faq' => 'faq'])
    ->middlewareFor('store', ['auth:sanctum', 'can:create,App\Models\Faq'])
    ->middlewareFor('update', ['auth:sanctum', 'can:update,faq'])
    ->middlewareFor('destroy', ['auth:sanctum', 'can:delete,faq']);

//Se sacan de la autenticacion porque es confirmacion de pago.
//Front recibe el token y lo envia a /webpay/return  (La ruta se establece en el webpayService: linea 59)
///webpay/return valida la token y entrega al front el estado del pago
Route::get('/webpay/return', [WebpayController::class, 'return'])->name('webpay.return');
Route::get('/webpay/status', [WebpayController::class, 'status']);
Route::post('/webpay/refund', [WebpayController::class, 'refund']);

// Configuraciones de Webpay - Solo superadmin puede editarlas
Route::get('/webpay/config', [SiteinfoController::class, 'webpayConfig']);
Route::middleware(['auth:sanctum', 'role:superadmin'])->group(function () {
    Route::put('/webpay/config', [SiteinfoController::class, 'updateWebpayConfig']);
});

Route::get('/siteinfo', [SiteinfoController::class, 'show']);
Route::get('/terms', [SiteinfoController::class, 'terms']);
Route::get('/privacy-policy', [SiteinfoController::class, 'privacyPolicy']);
Route::get('/customer-message', [SiteinfoController::class, 'customerMessage']);

Route::middleware(['auth:sanctum', 'role:editor|admin|superadmin'])->group(function () {
    Route::put('/siteinfo', [SiteinfoController::class, 'update']);
    Route::put('/terms', [SiteinfoController::class, 'updateTerms']);
    Route::put('/privacy-policy', [SiteinfoController::class, 'updatePrivacyPolicy']);
    Route::post('/customer-message', [SiteinfoController::class, 'updateCustomerMessage']);
});

Route::middleware(['auth:sanctum', 'role:admin|superadmin'])->group(function () {
    Route::get('/settings/prices', [SettingsController::class, 'index']);
    Route::put('/settings/prices', [SettingsController::class, 'update']);
});

// Ruta catch-all al final
Route::any('{url}', function() {
    return response()->json(['message' => 'Method Not Allowed.'], 405);
})->where('url', '.*');
