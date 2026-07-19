--TEST--
stdlib SoapClient typemap from_xml/to_xml (#21046)
--FILE--
<?php
function book_from_xml(string $xml): string
{
    if (preg_match('/<title[^>]*>([^<]*)</', $xml, $m)) {
        return 'MAPPED:'.$m[1];
    }

    return 'MAPPED';
}

function book_to_xml($value): string
{
    $title = is_string($value) ? $value : (string) $value;

    return '<book xmlns="urn:book"><title>'.htmlspecialchars($title, ENT_XML1).'</title></book>';
}

$resp = __DIR__ . '/test/fixtures/soap/book.response.xml';
if (!is_file($resp)) {
    $resp = dirname(__DIR__, 3) . '/fixtures/soap/book.response.xml';
}

$client = new SoapClient(null, [
    'location' => $resp,
    'uri' => 'urn:book',
    'trace' => 1,
    'typemap' => [[
        'type_ns' => 'urn:book',
        'type_name' => 'Book',
        'from_xml' => 'book_from_xml',
        'to_xml' => 'book_to_xml',
    ]],
]);

$r = $client->__soapCall('getBook', []);
echo (is_string($r) && $r === 'MAPPED:Dune') ? 'from_xml=1' : 'from_xml=0';
echo "\n";

$client->__soapCall('echo', [new SoapVar('Dune', null, 'Book', 'urn:book')]);
$req = (string) $client->__getLastRequest();
echo (str_contains($req, '<book xmlns="urn:book"><title>Dune</title></book>')) ? 'to_xml=1' : 'to_xml=0';
echo "\n";
?>
--EXPECT--
from_xml=1
to_xml=1
