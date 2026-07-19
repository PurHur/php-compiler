--TEST--
Dom\XMLDocument::createFromFile loads path + ValueError/Exception guards (#20808)
--SKIPIF--
<?php
if (!class_exists('Dom\\XMLDocument')) {
    die('skip Dom\\XMLDocument requires PHP_COMPILER_PROFILE=8.4 (#20808)');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$path = sys_get_temp_dir() . '/phpc_dom_xml_cff_' . getmypid() . '.xml';
file_put_contents($path, '<?xml version="1.0"?><root><item id="a">1</item></root>');
echo 'method=', method_exists(Dom\XMLDocument::class, 'createFromFile') ? 'yes' : 'no', "\n";
$doc = Dom\XMLDocument::createFromFile($path);
@unlink($path);
echo 'class=', get_class($doc), "\n";
$root = $doc->documentElement;
echo 'root=', ($root !== null ? $root->nodeName : 'NULL'), "\n";
echo 'items=', $doc->getElementsByTagName('item')->length, "\n";
try {
    Dom\XMLDocument::createFromFile('');
    echo "empty=unexpected\n";
} catch (ValueError $e) {
    echo 'empty=', str_contains($e->getMessage(), 'must not be empty') ? 'ok' : $e->getMessage(), "\n";
}
$missing = sys_get_temp_dir() . '/phpc_dom_xml_missing_' . getmypid() . '.xml';
@unlink($missing);
try {
    Dom\XMLDocument::createFromFile($missing);
    echo "missing=unexpected\n";
} catch (Throwable $e) {
    echo 'missing=', str_starts_with($e->getMessage(), 'Cannot open file ') ? 'ok' : $e->getMessage(), "\n";
}
try {
    Dom\XMLDocument::createFromFile('/tmp/x%00.xml');
    echo "nul=unexpected\n";
} catch (ValueError $e) {
    echo 'nul=', str_contains($e->getMessage(), 'percent-encoded NUL') ? 'ok' : $e->getMessage(), "\n";
}
?>
--EXPECT--
method=yes
class=Dom\XMLDocument
root=root
items=1
empty=ok
missing=ok
nul=ok
