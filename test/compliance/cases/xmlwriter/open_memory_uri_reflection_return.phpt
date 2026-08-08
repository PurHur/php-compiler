--TEST--
xmlwriter_open_memory/open_uri Reflection XMLWriter|false (#28786, ext/xmlwriter/php_xmlwriter.stub.php)
--FILE--
<?php
foreach (['xmlwriter_open_memory', 'xmlwriter_open_uri'] as $f) {
    $r = new ReflectionFunction($f);
    echo $f, ' return=', $r->hasReturnType() ? (string) $r->getReturnType() : '(none)', PHP_EOL;
}
$w = xmlwriter_open_memory();
echo 'runtime=', get_debug_type($w), PHP_EOL;
?>
--EXPECT--
xmlwriter_open_memory return=XMLWriter|false
xmlwriter_open_uri return=XMLWriter|false
runtime=XMLWriter