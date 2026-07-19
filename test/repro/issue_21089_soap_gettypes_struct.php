<?php

/**
 * Repro #21089 — SoapClient::__getTypes() returns Zend-like struct strings.
 */
$wsdl = __DIR__ . '/../fixtures/soap/book.wsdl';
$resp = __DIR__ . '/../fixtures/soap/book_no_xsi.response.xml';

$c = new SoapClient($wsdl, [
    'location' => $resp,
    'trace' => 1,
]);
$types = $c->__getTypes();
echo is_array($types) ? 'types=array' : 'types=0';
echo "\n";
$joined = is_array($types) ? implode("\n---\n", $types) : '';
echo (is_array($types) && count($types) >= 1 && str_contains($joined, 'struct Book')) ? 'has_struct=1' : 'has_struct=0';
echo "\n";
echo (is_array($types) && str_contains($joined, 'string title') && str_contains($joined, 'string author'))
    ? 'fields=1' : 'fields=0';
echo "\n";
// Exact shape for Book (php-src type_to_string).
$expected = "struct Book {\n string title;\n string author;\n}";
echo (is_array($types) && in_array($expected, $types, true)) ? 'exact=1' : 'exact=0';
echo "\n";
