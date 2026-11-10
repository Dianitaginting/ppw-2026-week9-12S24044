<?php

namespace App\Http\Controllers\Soap;

use App\Http\Controllers\Controller;
use App\Models\Peminjaman;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use SimpleXMLElement;

class LibrarySoapController extends Controller
{
    public function wsdl(): Response
    {
        $endpoint = htmlspecialchars(url('/soap/sipus'), ENT_XML1, 'UTF-8');
        $wsdl = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<definitions name="SipusClearanceService"
 targetNamespace="https://sipus.del.ac.id/soap"
 xmlns:tns="https://sipus.del.ac.id/soap"
 xmlns:soap="http://schemas.xmlsoap.org/wsdl/soap/"
 xmlns:xsd="http://www.w3.org/2001/XMLSchema"
 xmlns="http://schemas.xmlsoap.org/wsdl/">
 <types>
  <xsd:schema targetNamespace="https://sipus.del.ac.id/soap" elementFormDefault="qualified">
   <xsd:element name="CheckClearanceStatusRequest">
    <xsd:complexType><xsd:sequence>
     <xsd:element name="nim_peminjam" type="xsd:string"/>
    </xsd:sequence></xsd:complexType>
   </xsd:element>
   <xsd:element name="CheckClearanceStatusResponse">
    <xsd:complexType><xsd:sequence>
     <xsd:element name="nim_peminjam" type="xsd:string"/>
     <xsd:element name="active_loans" type="xsd:int"/>
     <xsd:element name="is_cleared" type="xsd:boolean"/>
    </xsd:sequence></xsd:complexType>
   </xsd:element>
  </xsd:schema>
 </types>
 <message name="CheckClearanceStatusRequest">
  <part name="parameters" element="tns:CheckClearanceStatusRequest"/>
 </message>
 <message name="CheckClearanceStatusResponse">
  <part name="parameters" element="tns:CheckClearanceStatusResponse"/>
 </message>
 <portType name="LibrarySoapPortType">
  <operation name="CheckClearanceStatus">
   <input message="tns:CheckClearanceStatusRequest"/>
   <output message="tns:CheckClearanceStatusResponse"/>
  </operation>
 </portType>
 <binding name="LibrarySoapBinding" type="tns:LibrarySoapPortType">
  <soap:binding style="document" transport="http://schemas.xmlsoap.org/soap/http"/>
  <operation name="CheckClearanceStatus">
   <soap:operation soapAction="https://sipus.del.ac.id/soap#CheckClearanceStatus"/>
   <input><soap:body use="literal"/></input>
   <output><soap:body use="literal"/></output>
  </operation>
 </binding>
 <service name="SipusClearanceService">
  <port name="LibrarySoapPort" binding="tns:LibrarySoapBinding">
   <soap:address location="{$endpoint}"/>
  </port>
 </service>
</definitions>
XML;

        return response($wsdl, 200)->header('Content-Type', 'text/xml; charset=utf-8');
    }

    public function handle(Request $request): Response
    {
        $rawXml = trim($request->getContent());

        if ($rawXml === '') {
            return $this->soapFault('soap:Client', 'Body XML SOAP tidak boleh kosong.');
        }

        if (stripos($rawXml, '<!DOCTYPE') !== false) {
            return $this->soapFault('soap:Client', 'Deklarasi DOCTYPE tidak diizinkan.');
        }

        $previousErrorSetting = libxml_use_internal_errors(true);

        try {
            $xml = simplexml_load_string($rawXml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOBLANKS);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorSetting);
        }

        $soapNamespace = 'http://schemas.xmlsoap.org/soap/envelope/';

        if (
            $xml === false
            || $xml->getName() !== 'Envelope'
            || ! in_array($soapNamespace, array_values($xml->getNamespaces(false)), true)
        ) {
            return $this->soapFault('soap:Client', 'Format XML SOAP tidak valid.');
        }

        $xml->registerXPathNamespace('soap', $soapNamespace);
        $xml->registerXPathNamespace('m', 'https://sipus.del.ac.id/soap');
        $bodyNodes = $xml->xpath('/soap:Envelope/soap:Body');
        $nimNodes = empty($bodyNodes)
            ? []
            : $bodyNodes[0]->xpath('./m:CheckClearanceStatusRequest/m:nim_peminjam');
        $nim = isset($nimNodes[0]) ? trim((string) $nimNodes[0]) : '';

        if ($nim === '') {
            return $this->soapFault('soap:Client', 'Elemen <nim_peminjam> wajib diisi.');
        }

        $activeLoans = Peminjaman::query()
            ->where('nim_peminjam', $nim)
            ->where('status', 'dipinjam')
            ->count();
        $safeNim = htmlspecialchars($nim, ENT_XML1, 'UTF-8');
        $isCleared = $activeLoans === 0 ? 'true' : 'false';

        $responseXml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"
 xmlns:m="https://sipus.del.ac.id/soap">
 <soap:Header/>
 <soap:Body>
  <m:CheckClearanceStatusResponse>
   <m:nim_peminjam>{$safeNim}</m:nim_peminjam>
   <m:active_loans>{$activeLoans}</m:active_loans>
   <m:is_cleared>{$isCleared}</m:is_cleared>
  </m:CheckClearanceStatusResponse>
 </soap:Body>
</soap:Envelope>
XML;

        return response($responseXml, 200)->header('Content-Type', 'text/xml; charset=utf-8');
    }

    private function soapFault(string $faultCode, string $faultMessage): Response
    {
        $safeMessage = htmlspecialchars($faultMessage, ENT_XML1, 'UTF-8');
        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
 <soap:Body>
  <soap:Fault>
   <faultcode>{$faultCode}</faultcode>
   <faultstring>{$safeMessage}</faultstring>
  </soap:Fault>
 </soap:Body>
</soap:Envelope>
XML;

        return response($xml, 500)->header('Content-Type', 'text/xml; charset=utf-8');
    }
}
