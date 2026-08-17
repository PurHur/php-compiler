<?php
/**
 * Repro: SoapServer SOAP 1.2 success envelope (php-src soap.c; #31921).
 */
declare(strict_types=1);

function echo12($x) {
    return $x;
}

$server = new SoapServer(null, ['uri' => 'http://example.com/', 'soap_version' => SOAP_1_2]);
$server->addFunction('echo12');
$req = '<?xml version="1.0"?><env:Envelope xmlns:env="http://www.w3.org/2003/05/soap-envelope"'
    .' xmlns:ns1="http://example.com/"><env:Body><ns1:echo12><x>hi</x></ns1:echo12></env:Body></env:Envelope>';
ob_start();
try {
    $server->handle($req);
} catch (Throwable $e) {
    echo 'THREW=', get_class($e), "\n";
}
$out = (string) ob_get_clean();
echo 'ENV12=', str_contains($out, '2003/05/soap-envelope') ? '1' : '0', "\n";
echo 'ENV11=', str_contains($out, 'schemas.xmlsoap.org/soap/envelope') ? '1' : '0', "\n";
echo 'ENC12=', str_contains($out, '2003/05/soap-encoding') ? '1' : '0', "\n";
echo 'ENC11=', str_contains($out, 'schemas.xmlsoap.org/soap/encoding/') ? '1' : '0', "\n";
echo 'env_prefix=', str_contains($out, '<env:Envelope') ? '1' : '0', "\n";

$server11 = new SoapServer(null, ['uri' => 'http://example.com/', 'soap_version' => SOAP_1_1]);
$server11->addFunction('echo12');
ob_start();
try {
    $server11->handle($req);
} catch (Throwable $e) {
    echo 'THREW11=', get_class($e), "\n";
}
$out11 = (string) ob_get_clean();
echo '11_env=', str_contains($out11, 'schemas.xmlsoap.org/soap/envelope') ? '1' : '0', "\n";
echo '11_enc=', str_contains($out11, 'schemas.xmlsoap.org/soap/encoding/') ? '1' : '0', "\n";
