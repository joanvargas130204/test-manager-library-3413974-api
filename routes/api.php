<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BookController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\LoanController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

// Rutas públicas
Route::post('/auth/login', [AuthController::class, 'login']);
Route::get('/health', [AuthController::class, 'health']);

// Rutas autenticadas (cualquier rol válido: admin o librarian)
Route::middleware('jwt.auth')->group(function () {
    // Categorías
    Route::get('/categories', [CategoryController::class, 'index']);

    // Libros (Lectura y descarga de portadas)
    Route::get('/books', [BookController::class, 'index']);
    Route::get('/books/{id}', [BookController::class, 'show']);
    Route::get('/books/{id}/cover', [BookController::class, 'getCover']);

    // Socios
    Route::get('/members', [MemberController::class, 'index']);
    Route::get('/members/{id}', [MemberController::class, 'show']);
    Route::post('/members', [MemberController::class, 'store']);

    // Préstamos y Devoluciones
    Route::post('/loans', [LoanController::class, 'store']);
    Route::get('/loans', [LoanController::class, 'index']);
    Route::get('/loans/overdue', [LoanController::class, 'overdue']);
    Route::get('/loans/{id}', [LoanController::class, 'show']);
    Route::post('/loans/{loan_id}/items/{item_id}/return', [LoanController::class, 'returnItem']);
});

// Rutas exclusivas para el Administrador
Route::middleware('jwt.auth:admin')->group(function () {
    // Alta de bibliotecarios
    Route::post('/users', [UserController::class, 'store']);

    // Gestión de Libros
    Route::post('/books', [BookController::class, 'store']);
    Route::put('/books/{id}', [BookController::class, 'update']);
    Route::delete('/books/{id}', [BookController::class, 'destroy']);
    Route::put('/books/{id}/cover', [BookController::class, 'uploadCover']);
    Route::delete('/books/{id}/cover', [BookController::class, 'deleteCover']);

    // Reporte de más prestados
    Route::get('/reports/most-borrowed', [ReportController::class, 'mostBorrowed']);
});
