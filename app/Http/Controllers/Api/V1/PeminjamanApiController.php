<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PeminjamanResource;
use App\Models\Peminjaman;
use App\Services\PeminjamanService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PeminjamanApiController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'in:dipinjam,dikembalikan'],
            'nim' => ['sometimes', 'string', 'max:255'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);
        $perPage = (int) ($validated['per_page'] ?? 10);

        $paginator = Peminjaman::query()
            ->with('buku')
            ->when(isset($validated['status']), fn ($query) => $query->where('status', $validated['status']))
            ->when(isset($validated['nim']), fn ($query) => $query->where('nim_peminjam', 'like', '%'.$validated['nim'].'%'))
            ->latest('tanggal_pinjam')
            ->paginate($perPage)
            ->withQueryString();

        $data = collect($paginator->items())
            ->map(fn (Peminjaman $peminjaman): array => (new PeminjamanResource($peminjaman))->resolve($request))
            ->values();

        return $this->successResponse($data, 'Daftar transaksi peminjaman berhasil diambil.', meta: [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total_items' => $paginator->total(),
            'total_pages' => $paginator->lastPage(),
            'links' => [
                'first' => $paginator->url(1),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
                'last' => $paginator->url($paginator->lastPage()),
            ],
        ]);
    }

    public function store(Request $request, PeminjamanService $service): JsonResponse
    {
        $validated = $request->validate([
            'buku_id' => ['required', 'integer', 'exists:bukus,id'],
            'nim_peminjam' => ['required', 'string', 'max:255'],
            'nama_peminjam' => ['required', 'string', 'max:255'],
            'durasi_hari' => ['required', 'integer', 'min:1', 'max:365'],
        ]);

        try {
            $peminjaman = $service->pinjam(
                (int) $validated['buku_id'],
                $validated['nim_peminjam'],
                $validated['nama_peminjam'],
                (int) $validated['durasi_hari'],
            );
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            $detail = $errors['buku_id'][0] ?? 'Transaksi peminjaman tidak dapat diproses.';
            $isDuplicate = str_contains(strtolower($detail), 'peminjaman aktif');

            return $this->problemResponse(
                title: $isDuplicate ? 'Conflict' : 'Unprocessable Content',
                detail: $detail,
                status: $isDuplicate ? 409 : 422,
                errorSlug: $isDuplicate ? 'duplicate-active-loan' : 'loan-not-processable',
                errors: $errors,
            );
        }

        $peminjaman->load('buku');

        return $this->createdResponse(
            new PeminjamanResource($peminjaman),
            url("/api/v1/peminjamans/{$peminjaman->id}"),
            'Peminjaman buku berhasil dicatat.',
        );
    }

    public function kembalikan(string $id, PeminjamanService $service): JsonResponse
    {
        try {
            $peminjaman = $service->kembalikan((int) $id);
        } catch (ValidationException $exception) {
            $errors = $exception->errors();

            return $this->problemResponse(
                title: 'Conflict',
                detail: $errors['status'][0] ?? 'Peminjaman ini tidak dapat dikembalikan.',
                status: 409,
                errorSlug: 'loan-already-returned',
                errors: $errors,
            );
        }

        $peminjaman->load('buku');

        return $this->successResponse(
            new PeminjamanResource($peminjaman),
            'Buku berhasil dikembalikan dan stok telah diperbarui.',
        );
    }
}
