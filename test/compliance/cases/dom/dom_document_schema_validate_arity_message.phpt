--TEST--
DOMDocument schema/relaxNG validate ArgumentCountError wording (#25323)
--FILE--
<?php
$doc = new DOMDocument();

foreach ([
    'schemaValidate',
    'relaxNGValidate',
    'schemaValidateSource',
    'relaxNGValidateSource',
] as $method) {
    try {
        $doc->$method();
        echo $method, ": no error\n";
    } catch (ArgumentCountError $e) {
        echo $method, ': ', $e->getMessage(), "\n";
    }
}
--EXPECT--
schemaValidate: DOMDocument::schemaValidate() expects at least 1 argument, 0 given
relaxNGValidate: DOMDocument::relaxNGValidate() expects exactly 1 argument, 0 given
schemaValidateSource: DOMDocument::schemaValidateSource() expects at least 1 argument, 0 given
relaxNGValidateSource: DOMDocument::relaxNGValidateSource() expects exactly 1 argument, 0 given
