--TEST--
stdlib SoapClient::__getTypes struct strings (#21089)
--FILE--
<?php
$wsdl = __DIR__ . '/test/fixtures/soap/book.wsdl';
$resp = __DIR__ . '/test/fixtures/soap/book_no_xsi.response.xml';
if (!is_file($wsdl)) {
    $wsdl = dirname(__DIR__, 3) . '/fixtures/soap/book.wsdl';
    $resp = dirname(__DIR__, 3) . '/fixtures/soap/book_no_xsi.response.xml';
}

$c = new SoapClient($wsdl, [
    'location' => $resp,
    'trace' => 1,
]);
$types = $c->__getTypes();
echo is_array($types) ? 'types=array' : 'types=0';
echo "\n";
$expected = "struct Book {\n string title;\n string author;\n}";
echo (is_array($types) && in_array($expected, $types, true)) ? 'exact=1' : 'exact=0';
echo "\n";
?>
--EXPECT--
types=array
exact=1
