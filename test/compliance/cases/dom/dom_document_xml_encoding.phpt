--TEST--
dom DOMDocument::$xmlEncoding read-only alias for $encoding (#18724)
--FILE--
<?php
$doc = new DOMDocument();
echo var_export($doc->xmlEncoding, true), "\n";
$doc->loadXML('<?xml version="1.0" encoding="UTF-8"?><root/>');
echo $doc->xmlEncoding, "\n";
echo ($doc->encoding === $doc->xmlEncoding) ? 'same' : 'diff', "\n";
$doc->encoding = 'ISO-8859-1';
echo $doc->xmlEncoding, "\n";
try {
    $doc->xmlEncoding = 'X';
    echo "no-error\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
NULL
UTF-8
same
ISO-8859-1
Cannot write read-only property DOMDocument::$xmlEncoding
