<?php
/**
 * Repro #31945 — SoapServer::fault actor/details under SOAP 1.2
 * (php-src ext/soap/soap.c serialize_response_call SOAP_1_2 branch).
 *
 * Zend 8.2: env:Detail present; actor URI is NOT emitted as env:Node or env:Role.
 */
function boom12($x) {
    global $server;
    $server->fault('Server', 'msg', 'http://actor', 'det');
    return 'never';
}

$server = new SoapServer(null, ['uri' => 'http://example.com/', 'soap_version' => SOAP_1_2]);
$server->addFunction('boom12');
$req = '<?xml version="1.0"?><env:Envelope xmlns:env="http://www.w3.org/2003/05/soap-envelope"'
    .' xmlns:ns1="http://example.com/"><env:Body><ns1:boom12><x>1</x></ns1:boom12></env:Body></env:Envelope>';
ob_start();
$threw = 'no';
try {
    $server->handle($req);
} catch (Throwable $e) {
    $threw = get_class($e);
}
$out = (string) ob_get_clean();
echo 'THREW=', $threw, "\n";
echo 'ENV12=', str_contains($out, '2003/05/soap-envelope') ? 1 : 0, "\n";
echo 'ENV11=', str_contains($out, 'schemas.xmlsoap.org/soap/envelope') ? 1 : 0, "\n";
echo 'HAS_FAULT=', str_contains($out, 'Fault') ? 1 : 0, "\n";
echo 'HAS_RECEIVER=', str_contains($out, 'Receiver') ? 1 : 0, "\n";
echo 'HAS_NODE=', str_contains($out, 'Node') ? 1 : 0, "\n";
echo 'HAS_ROLE=', str_contains($out, 'Role') ? 1 : 0, "\n";
echo 'HAS_ACTOR_URI=', str_contains($out, 'http://actor') ? 1 : 0, "\n";
echo 'HAS_ENV_DETAIL=', str_contains($out, 'Detail') ? 1 : 0, "\n";
echo 'HAS_11_DETAIL=', str_contains($out, '<detail>') ? 1 : 0, "\n";
echo 'HAS_DET=', str_contains($out, 'det') ? 1 : 0, "\n";
