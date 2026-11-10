<?php

namespace App\Services;

use App\Models\Buku;
use App\Models\Peminjaman;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PeminjamanService
{
    public function pinjam(
        int $bukuId,
        string $nimPeminjam,
        string $namaPeminjam,
        int $durasiHari = 7
    ): Peminjaman {
        try {
            return DB::transaction(function () use ($bukuId, $nimPeminjam, $namaPeminjam, $durasiHari) {
                $buku = Buku::where('id', $bukuId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($buku->stok <= 0) {
                    throw ValidationException::withMessages([
                        'buku_id' => 'Stok buku sedang habis.',
                    ]);
                }

                $sudahMeminjam = Peminjaman::query()
                    ->where('buku_id', $buku->id)
                    ->where('nim_peminjam', $nimPeminjam)
                    ->where('status', 'dipinjam')
                    ->exists();

                if ($sudahMeminjam) {
                    throw ValidationException::withMessages([
                        'buku_id' => 'NIM ini masih memiliki peminjaman aktif untuk buku tersebut.',
                    ]);
                }

                $buku->decrement('stok');

                return Peminjaman::create([
                    'kode_pinjam' => 'PJM-'.Str::upper(Str::random(10)),
                    'buku_id' => $buku->id,
                    'nim_peminjam' => $nimPeminjam,
                    'nama_peminjam' => $namaPeminjam,
                    'tanggal_pinjam' => now()->toDateString(),
                    'tanggal_jatuh_tempo' => now()->addDays($durasiHari)->toDateString(),
                    'status' => 'dipinjam',
                ]);
            });
        } catch (QueryException $exception) {
            Log::error('Database error while processing a book borrowing.', [
                'buku_id' => $bukuId,
                'exception' => $exception,
            ]);

            throw $exception;
        }
    }

    public function kembalikan(int $peminjamanId): Peminjaman
    {
        try {
            return DB::transaction(function () use ($peminjamanId) {
                $peminjaman = Peminjaman::whereKey($peminjamanId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($peminjaman->status !== 'dipinjam') {
                    throw ValidationException::withMessages([
                        'status' => 'Peminjaman ini sudah dikembalikan.',
                    ]);
                }

                $buku = Buku::where('id', $peminjaman->buku_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $buku->increment('stok');

                $peminjaman->update([
                    'tanggal_kembali' => now()->toDateString(),
                    'status' => 'dikembalikan',
                ]);

                return $peminjaman->fresh();
            });
        } catch (QueryException $exception) {
            Log::error('Database error while processing a book return.', [
                'peminjaman_id' => $peminjamanId,
                'exception' => $exception,
            ]);

            throw $exception;
        }
    }
}
