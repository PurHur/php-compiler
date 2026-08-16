--TEST--
stdlib SoapClient::__getTypes element-inline anonymous complexTypes (#31474)
--FILE--
<?php
$echo = __DIR__ . '/test/fixtures/soap/echo.wsdl';
$book = __DIR__ . '/test/fixtures/soap/book.wsdl';
if (!is_file($echo)) {
    $echo = dirname(__DIR__, 3) . '/fixtures/soap/echo.wsdl';
    $book = dirname(__DIR__, 3) . '/fixtures/soap/book.wsdl';
}
$opts = [
    'location' => 'http://127.0.0.1/',
    'uri' => 'http://test/',
    'cache_wsdl' => WSDL_CACHE_NONE,
];

$c = new SoapClient($echo, $opts);
$types = $c->__getTypes();
echo 'echo=', (is_array($types)
    && in_array("struct echo {\n string input;\n}", $types, true)
    && in_array("struct echoResponse {\n string output;\n}", $types, true)) ? 1 : 0, "\n";

$c2 = new SoapClient($book, $opts);
$types2 = $c2->__getTypes();
echo 'book=', (is_array($types2)
    && in_array("struct Book {\n string title;\n string author;\n}", $types2, true)
    && in_array("struct getBook {\n}", $types2, true)
    && in_array("struct getBookResponse {\n Book return;\n}", $types2, true)) ? 1 : 0, "\n";
?>
--EXPECT--
echo=1
book=1
