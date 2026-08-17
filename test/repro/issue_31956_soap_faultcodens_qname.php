<?php
/**
 * Repro #31956 — SoapFault array ($ns, $code) sets faultcodens; SOAP 1.2
 * env:Code/Value is a QName in that namespace (php-src ext/soap/soap.c
 * zim_SoapFault___construct + serialize_response_call xmlBuildQName).
 *
 * Zend 8.2: throw SoapFault(['http://example.com/app','AppError'], 'nope')
 * from a SOAP_1_2 handler → Value is ns1:AppError and Envelope declares
 * xmlns:ns1="http://example.com/app". $faultcodens is visible on the object.
 */
$probe = new SoapFault(['http://example.com/app', 'AppError'], 'nope');
echo 'PROP_CODE=', $probe->faultcode === 'AppError' ? 1 : 0, "\n";
echo 'PROP_NS=', (isset($probe->faultcodens) && $probe->faultcodens === 'http://example.com/app') ? 1 : 0, "\n";

function boom12($x)
{
    throw new SoapFault(['http://example.com/app', 'AppError'], 'nope');
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
echo 'HAS_APPERROR=', str_contains($out, 'AppError') ? 1 : 0, "\n";
echo 'HAS_APP_NS=', str_contains($out, 'http://example.com/app') ? 1 : 0, "\n";
echo 'VALUE_QN=', str_contains($out, '<env:Value>ns1:AppError</env:Value>') ? 1 : 0, "\n";
echo 'VALUE_BARE=', str_contains($out, '<env:Value>AppError</env:Value>') ? 1 : 0, "\n";
echo 'HAS_FAULTCODE=', str_contains($out, 'faultcode') ? 1 : 0, "\n";
