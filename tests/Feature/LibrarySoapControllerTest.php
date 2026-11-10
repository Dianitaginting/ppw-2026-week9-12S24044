<?php

namespace Tests\Feature;

use App\Models\Buku;
use App\Models\Kategori;
use App\Services\PeminjamanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use SimpleXMLElement;
use Tests\TestCase;

class LibrarySoapControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_wsdl_is_well_formed_and_describes_the_document_literal_operation(): void
    {
        $response = $this->get('/soap/sipus.wsdl');

        $response->assertOk()
            ->assertHeader('content-type', 'text/xml; charset=utf-8');

        $wsdl = simplexml_load_string($response->getContent());
        $this->assertInstanceOf(SimpleXMLElement::class, $wsdl);

        $wsdl->registerXPathNamespace('wsdl', 'http://schemas.xmlsoap.org/wsdl/');
        $wsdl->registerXPathNamespace('soap', 'http://schemas.xmlsoap.org/wsdl/soap/');
        $wsdl->registerXPathNamespace('xsd', 'http://www.w3.org/2001/XMLSchema');

        $this->assertCount(
            1,
            $wsdl->xpath('/wsdl:definitions/wsdl:portType/wsdl:operation[@name="CheckClearanceStatus"]')
        );
        $this->assertCount(1, $wsdl->xpath('//soap:binding[@style="document"]'));
        $this->assertCount(
            1,
            $wsdl->xpath('//xsd:element[@name="CheckClearanceStatusRequest"]//xsd:element[@name="nim_peminjam"]')
        );
        $this->assertStringContainsString(url('/soap/sipus'), $response->getContent());
    }

    public function test_soap_response_reports_active_loans_and_cleared_status(): void
    {
        $category = Kategori::create([
            'kode_kategori' => 'SOAP',
            'nama_kategori' => 'SOAP Test',
        ]);
        $book = Buku::create([
            'isbn' => 'SOAP-ISBN-001',
            'judul' => 'Buku SOAP',
            'penulis' => 'Penulis Test',
            'penerbit' => 'Penerbit Test',
            'tahun_terbit' => 2025,
            'kategori_id' => $category->id,
            'stok' => 1,
        ]);

        app(PeminjamanService::class)->pinjam($book->id, '12S24044', 'Mahasiswa Test');

        $activeResponse = $this->call(
            'POST',
            '/soap/sipus',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/xml; charset=utf-8'],
            $this->clearanceRequest('12S24044'),
        );

        $activeResponse->assertOk()
            ->assertHeader('content-type', 'text/xml; charset=utf-8')
            ->assertSee('<m:active_loans>1</m:active_loans>', false)
            ->assertSee('<m:is_cleared>false</m:is_cleared>', false);

        $clearedResponse = $this->call(
            'POST',
            '/soap/sipus',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/xml; charset=utf-8'],
            $this->clearanceRequest('12S00000'),
        );

        $clearedResponse->assertOk()
            ->assertSee('<m:active_loans>0</m:active_loans>', false)
            ->assertSee('<m:is_cleared>true</m:is_cleared>', false);
    }

    public function test_soap_fault_is_returned_for_malformed_or_missing_nim_payloads(): void
    {
        $malformedResponse = $this->postSoap('<soap:Envelope');
        $malformedResponse->assertStatus(500)
            ->assertHeader('content-type', 'text/xml; charset=utf-8')
            ->assertSee('<soap:Fault>', false);

        $emptyNimResponse = $this->postSoap($this->clearanceRequest('  '));
        $emptyNimResponse->assertStatus(500)
            ->assertSee('Elemen &lt;nim_peminjam&gt; wajib diisi.', false);

        $missingBodyResponse = $this->postSoap(
            '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"'
                .'><m:CheckClearanceStatusRequest xmlns:m="https://sipus.del.ac.id/soap">'
                .'<m:nim_peminjam>12S24044</m:nim_peminjam></m:CheckClearanceStatusRequest>'
                .'</soap:Envelope>'
        );

        $missingBodyResponse->assertStatus(500)
            ->assertSee('<soap:Fault>', false);
    }

    public function test_soap_fault_is_returned_when_xml_contains_a_doctype(): void
    {
        $response = $this->postSoap(
            '<!DOCTYPE foo [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'
                .'<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'
                .'<soap:Body><x>&xxe;</x></soap:Body></soap:Envelope>'
        );

        $response->assertStatus(500)
            ->assertSee('DOCTYPE', false)
            ->assertSee('<soap:Fault>', false);
    }

    public function test_soap_fault_is_returned_when_the_envelope_namespace_is_invalid(): void
    {
        $response = $this->postSoap(
            '<Envelope xmlns:m="https://sipus.del.ac.id/soap">'
                .'<soap:Body xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'
                .'<m:CheckClearanceStatusRequest><m:nim_peminjam>12S24044</m:nim_peminjam>'
                .'</m:CheckClearanceStatusRequest></soap:Body></Envelope>'
        );

        $response->assertStatus(500)
            ->assertSee('Format XML SOAP tidak valid.', false)
            ->assertSee('<soap:Fault>', false);
    }

    private function clearanceRequest(string $nim): string
    {
        $escapedNim = htmlspecialchars($nim, ENT_XML1, 'UTF-8');

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/" xmlns:m="https://sipus.del.ac.id/soap">
 <soap:Body>
  <m:CheckClearanceStatusRequest>
   <m:nim_peminjam>{$escapedNim}</m:nim_peminjam>
  </m:CheckClearanceStatusRequest>
 </soap:Body>
</soap:Envelope>
XML;
    }

    private function postSoap(string $xml): TestResponse
    {
        return $this->call(
            'POST',
            '/soap/sipus',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/xml; charset=utf-8'],
            $xml,
        );
    }
}
