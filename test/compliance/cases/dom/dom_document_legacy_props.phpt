--TEST--
dom DOMDocument::$version/$standalone/$actualEncoding/$config legacy aliases (#28587)
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<?xml version="1.0" encoding="ISO-8859-1" standalone="yes"?><r/>');
echo $doc->version, "\n";
echo ($doc->version === $doc->xmlVersion) ? "version-same\n" : "version-diff\n";
echo $doc->standalone ? "standalone-yes\n" : "standalone-no\n";
echo ($doc->standalone === $doc->xmlStandalone) ? "standalone-same\n" : "standalone-diff\n";
echo $doc->actualEncoding, "\n";
echo ($doc->actualEncoding === $doc->encoding) ? "encoding-same\n" : "encoding-diff\n";
var_export($doc->config);
echo "\n";

$doc->version = '1.1';
echo $doc->xmlVersion, "\n";
$doc->standalone = false;
echo $doc->xmlStandalone ? "xml-standalone-yes\n" : "xml-standalone-no\n";

$r = new ReflectionClass('DOMDocument');
foreach (['version', 'standalone', 'actualEncoding', 'config'] as $p) {
    echo $p, ':', $r->hasProperty($p) ? '1' : '0', "\n";
}

try {
    $doc->actualEncoding = 'X';
    echo "actualEncoding-wrote\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
try {
    $doc->config = 'X';
    echo "config-wrote\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
1.0
version-same
standalone-yes
standalone-same
ISO-8859-1
encoding-same
NULL
1.1
xml-standalone-no
version:1
standalone:1
actualEncoding:1
config:1
Cannot write read-only property DOMDocument::$actualEncoding
Cannot write read-only property DOMDocument::$config
