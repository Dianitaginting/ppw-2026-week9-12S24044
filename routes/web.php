
<?php

use App\Http\Controllers\Auth\PetugasAuthController;
use App\Http\Controllers\BukuController;
use App\Http\Controllers\KategoriController;
use App\Http\Controllers\PeminjamanController;
use App\Http\Controllers\Soap\LibrarySoapController;
use Illuminate\Support\Facades\Route;

// Halaman utama
Route::get('/', function () {
    return redirect()->route('buku.index');
});

Route::get('/soap/sipus.wsdl', [LibrarySoapController::class, 'wsdl']);
Route::post('/soap/sipus', [LibrarySoapController::class, 'handle']);

// Route autentikasi
Route::middleware('guest')->group(function () {
    Route::get('/login', [
        PetugasAuthController::class,
        'showLogin',
    ])->name('login');

    Route::post('/login', [
        PetugasAuthController::class,
        'login',
    ])->name('login.store');
});

// Logout hanya untuk pengguna yang sudah login
Route::post('/logout', [
    PetugasAuthController::class,
    'logout',
])->middleware('auth')->name('logout');

// Route yang hanya dapat diakses setelah login
Route::middleware('auth')->group(function () {
    Route::resource('kategori', KategoriController::class);

    Route::resource('buku', BukuController::class);

    Route::get('/peminjaman', [
        PeminjamanController::class,
        'index',
    ])->name('peminjaman.index');

    Route::post('/peminjaman', [
        PeminjamanController::class,
        'store',
    ])->name('peminjaman.store');

    Route::patch('/peminjaman/{peminjaman}/kembalikan', [
        PeminjamanController::class,
        'kembalikan',
    ])->name('peminjaman.kembalikan');
});
