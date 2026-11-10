<?php

namespace Tests\Feature;

use App\Models\Buku;
use App\Models\Kategori;
use App\Models\Peminjaman;
use App\Models\User;
use App\Services\PeminjamanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RestApiTest extends TestCase
{
    use RefreshDatabase;

    private function kategori(): Kategori
    {
        return Kategori::create([
            'kode_kategori' => 'API-'.fake()->unique()->numerify('####'),
            'nama_kategori' => 'Kategori API',
        ]);
    }

    private function buku(int $stok = 3, ?Kategori $kategori = null): Buku
    {
        return Buku::create([
            'isbn' => '978-'.fake()->unique()->numerify('#############'),
            'judul' => 'Laravel API Test',
            'penulis' => 'Penulis Test',
            'penerbit' => 'Penerbit Test',
            'tahun_terbit' => 2025,
            'kategori_id' => ($kategori ?? $this->kategori())->id,
            'stok' => $stok,
            'sinopsis' => 'Sinopsis awal.',
        ]);
    }

    private function bearerToken(?User $user = null): string
    {
        return ($user ?? User::factory()->create())
            ->createToken('rest-api-test')
            ->plainTextToken;
    }

    public function test_catalog_filters_paginates_and_exposes_dynamic_links(): void
    {
        $category = $this->kategori();
        $available = $this->buku(2, $category);
        $unavailable = $this->buku(0, $category);

        $response = $this->getJson('/api/v1/bukus?search=Laravel&kategori_id='.$category->id.'&tersedia=1&per_page=1');

        $response->assertOk()
            ->assertJsonPath('meta.total_items', 1)
            ->assertJsonPath('data.0.id', $available->id)
            ->assertJsonPath('data.0.kategori.id', $category->id)
            ->assertJsonPath('data.0._links.pinjam_buku.method', 'POST')
            ->assertJsonMissingPath('data.1');

        $unavailableResponse = $this->getJson("/api/v1/bukus/{$unavailable->id}");

        $unavailableResponse->assertOk()
            ->assertJsonMissingPath('data._links.pinjam_buku')
            ->assertJsonPath('data._links.self.method', 'GET');
    }

    public function test_book_detail_includes_borrowing_history_and_missing_book_uses_problem_details(): void
    {
        $book = $this->buku();
        $loan = app(PeminjamanService::class)
            ->pinjam($book->id, '12S24044', 'Mahasiswa Test');

        $this->getJson("/api/v1/bukus/{$book->id}")
            ->assertOk()
            ->assertJsonPath('data.peminjamans.0.id', $loan->id)
            ->assertJsonPath('data.peminjamans.0._links.kembalikan_buku.method', 'PATCH');

        $this->getJson('/api/v1/bukus/999999')
            ->assertNotFound()
            ->assertHeader('content-type', 'application/problem+json')
            ->assertJsonPath('status', 404)
            ->assertJsonStructure(['type', 'title', 'status', 'detail', 'instance']);
    }

    public function test_book_mutations_require_auth_and_enforce_validation_and_put_patch_semantics(): void
    {
        $category = $this->kategori();
        $payload = [
            'isbn' => '9781234567890',
            'judul' => 'Buku REST',
            'penulis' => 'Penulis',
            'penerbit' => 'Penerbit',
            'tahun_terbit' => 2025,
            'kategori_id' => $category->id,
            'stok' => 4,
            'sinopsis' => 'Keterangan lengkap.',
        ];

        $this->postJson('/api/v1/bukus', $payload)
            ->assertUnauthorized()
            ->assertHeader('content-type', 'application/problem+json')
            ->assertJsonPath('status', 401);

        $token = $this->bearerToken();

        $this->withToken($token)->postJson('/api/v1/bukus', [])
            ->assertUnprocessable()
            ->assertHeader('content-type', 'application/problem+json')
            ->assertJsonStructure(['type', 'title', 'status', 'detail', 'instance', 'errors']);

        $created = $this->withToken($token)->postJson('/api/v1/bukus', $payload)
            ->assertCreated()
            ->assertHeader('Location');

        $bookId = $created->json('data.id');
        $created->assertHeader('Location', url("/api/v1/bukus/{$bookId}"));

        $this->withToken($token)->patchJson("/api/v1/bukus/{$bookId}", ['stok' => 8])
            ->assertOk()
            ->assertJsonPath('data.stok', 8)
            ->assertJsonPath('data.sinopsis', 'Keterangan lengkap.');

        $this->withToken($token)->putJson("/api/v1/bukus/{$bookId}", [
            ...$payload,
            'stok' => 10,
            'sinopsis' => null,
        ])->assertOk()
            ->assertJsonPath('data.stok', 10)
            ->assertJsonPath('data.sinopsis', null);

        $this->withToken($token)->putJson("/api/v1/bukus/{$bookId}", [
            'judul' => 'Representasi tidak lengkap',
        ])->assertUnprocessable();
    }

    public function test_delete_book_conflicts_with_active_loan_then_returns_no_content_when_safe(): void
    {
        $book = $this->buku();
        $token = $this->bearerToken();
        app(PeminjamanService::class)->pinjam($book->id, '12S24044', 'Mahasiswa Test');

        $this->withToken($token)->deleteJson("/api/v1/bukus/{$book->id}")
            ->assertStatus(409)
            ->assertHeader('content-type', 'application/problem+json');

        $loan = Peminjaman::query()->firstOrFail();
        app(PeminjamanService::class)->kembalikan($loan->id);

        $this->withToken($token)->deleteJson("/api/v1/bukus/{$book->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('bukus', ['id' => $book->id]);
    }

    public function test_sanctum_login_me_and_logout_revoke_the_current_token(): void
    {
        $user = User::factory()->create([
            'email' => 'pustakawan-api@del.ac.id',
            'password' => 'password123',
        ]);

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
            'device_name' => 'postman',
        ])->assertOk();

        $token = $login->json('data.access_token');

        $this->withToken($token)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', $user->email);

        $this->withToken($token)->postJson('/api/v1/auth/logout')
            ->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);

        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')
            ->assertUnauthorized()
            ->assertHeader('content-type', 'application/problem+json');
    }

    public function test_borrowing_uses_duration_updates_stock_and_rejects_empty_stock_and_duplicates(): void
    {
        $book = $this->buku(2);
        $token = $this->bearerToken();
        $body = [
            'buku_id' => $book->id,
            'nim_peminjam' => '12S24044',
            'nama_peminjam' => 'Mahasiswa Test',
            'durasi_hari' => 14,
        ];

        $created = $this->withToken($token)->postJson('/api/v1/peminjamans', $body)
            ->assertCreated()
            ->assertHeader('Location')
            ->assertJsonPath('data._links.kembalikan_buku.method', 'PATCH');

        $loanId = $created->json('data.id');
        $this->assertSame(1, $book->fresh()->stok);
        $this->assertSame(today()->addDays(14)->toDateString(), Peminjaman::findOrFail($loanId)->tanggal_jatuh_tempo->toDateString());

        $this->withToken($token)->postJson('/api/v1/peminjamans', $body)
            ->assertStatus(409)
            ->assertHeader('content-type', 'application/problem+json');

        $this->withToken($token)->postJson('/api/v1/peminjamans', [
            ...$body,
            'nim_peminjam' => '12S24045',
        ])->assertCreated();

        $emptyBook = $this->buku(0);
        $this->withToken($token)->postJson('/api/v1/peminjamans', [
            ...$body,
            'buku_id' => $emptyBook->id,
            'nim_peminjam' => '12S24046',
        ])->assertUnprocessable()
            ->assertJsonPath('detail', 'Stok buku sedang habis.');

        $this->assertSame(0, $emptyBook->fresh()->stok);
    }

    public function test_return_is_atomic_and_cannot_be_repeated(): void
    {
        $book = $this->buku(1);
        $token = $this->bearerToken();
        $loan = app(PeminjamanService::class)
            ->pinjam($book->id, '12S24044', 'Mahasiswa Test');

        $this->withToken($token)->getJson('/api/v1/peminjamans?status=dipinjam&nim=12S24044')
            ->assertOk()
            ->assertJsonPath('meta.total_items', 1);

        $this->withToken($token)->patchJson("/api/v1/peminjamans/{$loan->id}/kembalikan")
            ->assertOk()
            ->assertJsonPath('data.status', 'dikembalikan')
            ->assertJsonMissingPath('data._links.kembalikan_buku');

        $this->assertSame(1, $book->fresh()->stok);

        $this->withToken($token)->patchJson("/api/v1/peminjamans/{$loan->id}/kembalikan")
            ->assertStatus(409)
            ->assertHeader('content-type', 'application/problem+json');
    }

    public function test_open_library_consumer_maps_external_metadata(): void
    {
        $book = $this->buku();
        $key = "ISBN:{$book->isbn}";

        Http::fake([
            'openlibrary.org/*' => Http::response([
                $key => [
                    'title' => 'Open Library Title',
                    'number_of_pages' => 250,
                    'publishers' => [['name' => 'Open Publisher']],
                    'preview_url' => 'https://openlibrary.org/preview',
                ],
            ]),
        ]);

        $this->getJson("/api/v1/bukus/{$book->id}/referensi-eksternal")
            ->assertOk()
            ->assertJsonPath('data.judul', 'Open Library Title')
            ->assertJsonPath('data.jumlah_halaman', 250)
            ->assertJsonPath('data.penerbit.0', 'Open Publisher');
    }

    public function test_open_library_consumer_returns_not_found_for_missing_external_metadata(): void
    {
        $book = $this->buku();

        Http::fake(['openlibrary.org/*' => Http::response([], 200)]);

        $this->getJson("/api/v1/bukus/{$book->id}/referensi-eksternal")
            ->assertNotFound()
            ->assertHeader('content-type', 'application/problem+json');
    }

    public function test_open_library_consumer_returns_bad_gateway_on_timeout(): void
    {
        $book = $this->buku();

        Http::fake(function (): never {
            throw new ConnectionException('Connection timed out.');
        });

        $this->getJson("/api/v1/bukus/{$book->id}/referensi-eksternal")
            ->assertStatus(502)
            ->assertHeader('content-type', 'application/problem+json');
    }
}
