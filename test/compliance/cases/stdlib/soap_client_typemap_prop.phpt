--TEST--
Stdlib: SoapClient::$typemap is array under PROFILE=8.4 (#23903, ext/soap/soap.stub.php)
--FILE--
<?php
declare(strict_types=1);

$plain = new SoapClient(null, [
    'location' => 'http://127.0.0.1/',
    'uri' => 'http://test/',
]);
echo 'exists=', (int) property_exists($plain, 'typemap'), "\n";
echo 'null=', (int) (null === $plain->typemap), "\n";

$mapped = new SoapClient(null, [
    'location' => 'http://127.0.0.1/',
    'uri' => 'urn:book',
    'typemap' => [[
        'type_ns' => 'urn:book',
        'type_name' => 'Book',
        'from_xml' => 'strval',
    ]],
]);
echo 'is_array=', (int) is_array($mapped->typemap), "\n";
echo 'not_resource=', (int) (!is_resource($mapped->typemap)), "\n";
echo 'non_null=', (int) ($mapped->typemap !== null), "\n";
echo 'has_entry=', (int) (isset($mapped->typemap[0]['type_name']) && $mapped->typemap[0]['type_name'] === 'Book'), "\n";
?>
--EXPECT--
exists=1
null=1
is_array=1
not_resource=1
non_null=1
has_entry=1
