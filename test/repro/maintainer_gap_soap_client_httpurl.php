<?php
// repro #23246 — SoapClient::$httpurl is ?Soap\Url (null until HTTP request)
declare(strict_types=1);

$c = new SoapClient(null, [
    'location' => 'http://127.0.0.1/',
    'uri' => 'http://test/',
]);

if (!property_exists($c, 'httpurl')) {
    fwrite(STDERR, "property_exists httpurl missing\n");
    exit(1);
}

$v = $c->httpurl;
if (null !== $v) {
    fwrite(STDERR, "expected null before request, got ".get_debug_type($v)."\n");
    exit(1);
}

// Unit-level attach: successful HTTP path sets Soap\Url (php-src php_http.c).
// Use Reflection-free fixture: invoke __doRequest against a local file location
// (no httpurl), then verify null stayed; property still declared.
$dir = sys_get_temp_dir().'/phpc_soap_httpurl_'.getmypid();
@mkdir($dir);
$resp = $dir.'/r.xml';
file_put_contents($resp, '<?xml version="1.0"?><Envelope><Body><pingResponse/></Body></Envelope>');
$c2 = new SoapClient(null, [
    'location' => $resp,
    'uri' => 'http://test/',
    'trace' => true,
]);
$c2->__doRequest('<x/>', $resp, 'ping', SOAP_1_1);
if (null !== $c2->httpurl) {
    fwrite(STDERR, "fixture location should leave httpurl null\n");
    exit(1);
}

echo "ok\n";
@unlink($resp);
@rmdir($dir);
