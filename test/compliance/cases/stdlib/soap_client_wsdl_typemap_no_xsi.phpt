--TEST--
stdlib SoapClient WSDL typemap from_xml without xsi:type (#21090)
--FILE--
<?php
function book_from_xml(string $xml): string
{
    if (preg_match('/<title[^>]*>([^<]*)</', $xml, $m)) {
        return 'MAPPED:'.$m[1];
    }

    return 'MAPPED';
}

$wsdl = __DIR__ . '/test/fixtures/soap/book.wsdl';
$resp = __DIR__ . '/test/fixtures/soap/book_no_xsi.response.xml';
if (!is_file($wsdl)) {
    $wsdl = dirname(__DIR__, 3) . '/fixtures/soap/book.wsdl';
    $resp = dirname(__DIR__, 3) . '/fixtures/soap/book_no_xsi.response.xml';
}

$client = new SoapClient($wsdl, [
    'location' => $resp,
    'trace' => 1,
    'typemap' => [[
        'type_ns' => 'urn:book',
        'type_name' => 'Book',
        'from_xml' => 'book_from_xml',
    ]],
]);
$r = $client->__soapCall('getBook', []);
echo (is_string($r) && $r === 'MAPPED:Dune') ? 'from_xml=1' : 'from_xml=0';
echo "\n";
?>
--EXPECT--
from_xml=1
