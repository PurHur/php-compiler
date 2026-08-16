<?php
/**
 * Repro #31474 — SoapClient::__getTypes includes element-inline anonymous complexTypes.
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
$types = $c->__getTypes();
echo 'echo_count=', is_array($types) ? count($types) : 0, "\n";
echo 'echo_has=', (int) (is_array($types) && in_array("struct echo {\n string input;\n}", $types, true)
    && in_array("struct echoResponse {\n string output;\n}", $types, true)), "\n";

$c2 = new SoapClient($book, $opts);
$types2 = $c2->__getTypes();
echo 'book_count=', is_array($types2) ? count($types2) : 0, "\n";
echo 'book_has=', (int) (is_array($types2)
    && in_array("struct Book {\n string title;\n string author;\n}", $types2, true)
    && in_array("struct getBook {\n}", $types2, true)
    && in_array("struct getBookResponse {\n Book return;\n}", $types2, true)), "\n";
