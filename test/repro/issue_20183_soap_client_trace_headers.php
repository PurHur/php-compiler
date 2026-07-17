<?php
$wsdl = __DIR__ . '/../fixtures/soap/echo.wsdl';
$resp = __DIR__ . '/../fixtures/soap/echo.response.xml';

$client = new SoapClient($wsdl, [
    'location' => $resp,
    'uri' => 'http://example.com/echo',
    'trace' => 1,
]);

echo 'has_req_hdr=', method_exists($client, '__getLastRequestHeaders') ? 1 : 0, "\n";
echo 'has_res_hdr=', method_exists($client, '__getLastResponseHeaders') ? 1 : 0, "\n";

$beforeReq = $client->__getLastRequestHeaders();
$beforeRes = $client->__getLastResponseHeaders();
echo 'before_req=', var_export($beforeReq, true), "\n";
echo 'before_res=', var_export($beforeRes, true), "\n";

$out = $client->__soapCall('echo', [['input' => 'hello']]);
echo 'out=', $out, "\n";

$reqHdr = $client->__getLastRequestHeaders();
$resHdr = $client->__getLastResponseHeaders();
echo 'req_is_str=', is_string($reqHdr) ? 1 : 0, "\n";
echo 'res_is_str=', is_string($resHdr) ? 1 : 0, "\n";
echo 'req_has_ct=', (is_string($reqHdr) && str_contains($reqHdr, 'Content-Type')) ? 1 : 0, "\n";
echo 'req_has_sa=', (is_string($reqHdr) && str_contains($reqHdr, 'SOAPAction')) ? 1 : 0, "\n";
echo 'res_has_ct=', (is_string($resHdr) && str_contains($resHdr, 'Content-Type')) ? 1 : 0, "\n";

$noTrace = new SoapClient($wsdl, [
    'location' => $resp,
    'uri' => 'http://example.com/echo',
    'trace' => 0,
]);
$noTrace->__soapCall('echo', [['input' => 'hello']]);
echo 'no_trace_req=', var_export($noTrace->__getLastRequestHeaders(), true), "\n";
echo 'no_trace_res=', var_export($noTrace->__getLastResponseHeaders(), true), "\n";
