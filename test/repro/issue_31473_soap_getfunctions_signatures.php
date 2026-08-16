<?php
/**
 * Repro #31473 — SoapClient::__getFunctions Zend function_to_string signatures.
 * Requires host php-soap for advertisement.
 */
if (!extension_loaded('soap')) {
    fwrite(STDERR, "soap not advertised\n");
    exit(2);
}

$opts = [
    'location' => 'http://127.0.0.1/',
    'uri' => 'http://test/',
    'cache_wsdl' => WSDL_CACHE_NONE,
];
$echo = __DIR__ . '/../fixtures/soap/echo.wsdl';
$book = __DIR__ . '/../fixtures/soap/book.wsdl';

$c = new SoapClient($echo, $opts);
echo 'echo=', $c->__getFunctions()[0] ?? 'missing', "\n";
$c2 = new SoapClient($book, $opts);
echo 'book=', $c2->__getFunctions()[0] ?? 'missing', "\n";
