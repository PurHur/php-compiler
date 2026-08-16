--TEST--
stdlib SoapClient::__getFunctions Zend function_to_string signatures (#31473)
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
$fns = $c->__getFunctions();
echo 'echo=', (is_array($fns) && isset($fns[0])) ? $fns[0] : 'missing', "\n";

$c2 = new SoapClient($book, $opts);
$fns2 = $c2->__getFunctions();
echo 'book=', (is_array($fns2) && isset($fns2[0])) ? $fns2[0] : 'missing', "\n";
?>
--EXPECT--
echo=echoResponse echo(echo $parameters)
book=getBookResponse getBook(getBook $parameters)
