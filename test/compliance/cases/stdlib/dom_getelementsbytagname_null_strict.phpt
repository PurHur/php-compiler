--TEST--
DOMDocument::getElementsByTagName(null) TypeError under strict_types (#29959, ext/dom/php_dom.c)
--FILE--
<?php
declare(strict_types=1);
$d = new DOMDocument();
$d->loadXML('<r><a/></r>');
try {
    $d->getElementsByTagName(null);
    echo "fail\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
TypeError
DOMDocument::getElementsByTagName(): Argument #1 ($qualifiedName) must be of type string, null given
