<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\BukuResource;
use App\Models\Buku;
use App\Traits\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class BukuApiController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $allowedSorts = ['isbn', 'judul', 'penulis', 'penerbit', 'tahun_terbit', 'stok', 'created_at'];
        $sort = $request->query('sort', 'judul');
        $sortBy = in_array($sort, $allowedSorts, true) ? $sort : 'judul';
        $sortDirection = strtolower((string) $request->query('dir', 'asc')) === 'desc' ? 'desc' : 'asc';
        $perPage = min(max((int) $request->query('per_page', 10), 1), 50);

        $paginator = Buku::query()
            ->with('kategori')
            ->withCount('peminjamans')
            ->when($request->filled('search'), function (Builder $query) use ($request): void {
                $search = trim((string) $request->query('search'));
                $query->where(function (Builder $searchQuery) use ($search): void {
                    $searchQuery
                        ->where('judul', 'like', "%{$search}%")
                        ->orWhere('penulis', 'like', "%{$search}%")
                        ->orWhere('isbn', 'like', "%{$search}%");
                });
            })
            ->when($request->filled('kategori_id'), fn (Builder $query) => $query->where('kategori_id', $request->query('kategori_id')))
            ->when($request->boolean('tersedia'), fn (Builder $query) => $query->where('stok', '>', 0))
            ->orderBy($sortBy, $sortDirection)
            ->paginate($perPage)
            ->withQueryString();

        $data = collect($paginator->items())
            ->map(fn (Buku $buku): array => (new BukuResource($buku))->resolve($request))
            ->values();

        return $this->successResponse($data, 'Katalog buku berhasil diambil.', meta: [
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

    public function show(string $id): JsonResponse
    {
        $buku = Buku::query()
            ->with(['kategori', 'peminjamans' => fn ($query) => $query->latest('tanggal_pinjam')])
            ->withCount('peminjamans')
            ->find($id);

        if (! $buku) {
            return $this->problemResponse(
                title: 'Not Found',
                detail: "Buku dengan ID {$id} tidak ditemukan.",
                status: 404,
                errorSlug: 'buku-not-found',
            );
        }

        return $this->successResponse(
            new BukuResource($buku),
            'Detail buku berhasil diambil.',
        );
    }

    public function store(Request $request): JsonResponse
    {
        $rules = $this->bukuRules(fullReplacement: true);
        $rules['sinopsis'] = ['sometimes', 'nullable', 'string'];
        $validated = $request->validate($rules);
        $buku = Buku::create($validated)->load(['kategori'])->loadCount('peminjamans');

        return $this->createdResponse(
            new BukuResource($buku),
            url("/api/v1/bukus/{$buku->id}"),
            'Buku baru berhasil ditambahkan.',
        );
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $buku = Buku::find($id);

        if (! $buku) {
            return $this->problemResponse(
                title: 'Not Found',
                detail: "Buku dengan ID {$id} tidak ditemukan.",
                status: 404,
                errorSlug: 'buku-not-found',
            );
        }

        $isPut = $request->isMethod('put');
        $rules = $this->bukuRules($buku->id, $isPut);
        $validated = $request->validate($rules);

        if ($isPut) {
            $validated['sinopsis'] = $request->input('sinopsis');
        } elseif ($validated === []) {
            return $this->problemResponse(
                title: 'Bad Request',
                detail: 'PATCH harus menyertakan setidaknya satu atribut untuk diperbarui.',
                status: 400,
                errorSlug: 'empty-patch-payload',
            );
        }

        $buku->update($validated);
        $buku->load('kategori')->loadCount('peminjamans');

        return $this->successResponse(
            new BukuResource($buku),
            $isPut ? 'Representasi buku berhasil diganti.' : 'Buku berhasil diperbarui sebagian.',
        );
    }

    public function destroy(string $id): JsonResponse
    {
        $buku = Buku::withCount([
            'peminjamans as peminjaman_aktif_count' => fn (Builder $query) => $query->where('status', 'dipinjam'),
        ])->find($id);

        if (! $buku) {
            return $this->problemResponse(
                title: 'Not Found',
                detail: "Buku dengan ID {$id} tidak ditemukan.",
                status: 404,
                errorSlug: 'buku-not-found',
            );
        }

        if ($buku->peminjaman_aktif_count > 0) {
            return $this->problemResponse(
                title: 'Conflict',
                detail: 'Buku tidak dapat dihapus karena masih memiliki peminjaman aktif.',
                status: 409,
                errorSlug: 'buku-has-active-loans',
            );
        }

        $buku->delete();

        return $this->noContentResponse();
    }

    public function referensiEksternal(string $id): JsonResponse
    {
        $buku = Buku::find($id);

        if (! $buku) {
            return $this->problemResponse(
                title: 'Not Found',
                detail: "Buku dengan ID {$id} tidak ditemukan.",
                status: 404,
                errorSlug: 'buku-not-found',
            );
        }

        try {
            $response = Http::acceptJson()
                ->timeout(8)
                ->get('https://openlibrary.org/api/books', [
                    'bibkeys' => "ISBN:{$buku->isbn}",
                    'format' => 'json',
                    'jscmd' => 'data',
                ]);
        } catch (ConnectionException) {
            return $this->problemResponse(
                title: 'Bad Gateway',
                detail: 'Layanan Open Library tidak dapat dijangkau atau melewati batas waktu.',
                status: 502,
                errorSlug: 'external-service-unavailable',
            );
        }

        if ($response->failed()) {
            return $this->problemResponse(
                title: 'Bad Gateway',
                detail: 'Layanan Open Library mengembalikan respons yang tidak berhasil.',
                status: 502,
                errorSlug: 'external-service-error',
            );
        }

        $bookData = $response->json("ISBN:{$buku->isbn}");

        if (! is_array($bookData)) {
            return $this->problemResponse(
                title: 'Not Found',
                detail: 'Metadata ISBN tersebut tidak ditemukan di Open Library.',
                status: 404,
                errorSlug: 'external-book-not-found',
            );
        }

        return $this->successResponse([
            'isbn' => $buku->isbn,
            'judul' => $bookData['title'] ?? null,
            'jumlah_halaman' => $bookData['number_of_pages'] ?? null,
            'penerbit' => collect($bookData['publishers'] ?? [])->pluck('name')->values(),
            'pratinjau_url' => $bookData['preview_url'] ?? $bookData['url'] ?? null,
            'sumber' => 'Open Library',
        ], 'Referensi eksternal buku berhasil diambil.');
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function bukuRules(?int $ignoreId = null, bool $fullReplacement = false): array
    {
        $presence = $fullReplacement ? ['required'] : ['sometimes', 'required'];
        $uniqueIsbn = 'unique:bukus,isbn'.($ignoreId ? ",{$ignoreId}" : '');

        return [
            'isbn' => [...$presence, 'string', 'max:20', $uniqueIsbn],
            'judul' => [...$presence, 'string', 'max:255'],
            'penulis' => [...$presence, 'string', 'max:150'],
            'penerbit' => [...$presence, 'string', 'max:150'],
            'tahun_terbit' => [...$presence, 'integer', 'min:0', 'max:9999'],
            'kategori_id' => [...$presence, 'integer', 'exists:kategoris,id'],
            'stok' => [...$presence, 'integer', 'min:0'],
            'sinopsis' => [$fullReplacement ? 'present' : 'sometimes', 'nullable', 'string'],
        ];
    }
}
