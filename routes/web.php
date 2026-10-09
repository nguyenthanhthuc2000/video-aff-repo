<?php

use App\Http\Controllers\FashionModelController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductImageController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('welcome');
})->name('home');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', function () {
        return to_route('products.index');
    })->name('dashboard');

    Route::resource('products', ProductController::class)->except('show');
    Route::post('product-images/{image}/primary', [ProductImageController::class, 'makePrimary'])->name('product-images.primary');
    Route::delete('product-images/{image}', [ProductImageController::class, 'destroy'])->name('product-images.destroy');

    Route::resource('fashion-models', FashionModelController::class)->only(['index', 'store', 'destroy']);
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
