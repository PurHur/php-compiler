--TEST--
JIT: DOMDocument::getElementById(null) TypeError under strict_types (#29942, ext/dom/php_dom.c)
--FILE--
<?php
declare(strict_types=1);
$d = new DOMDocument();
$d->loadXML('<r id="x"/>');
$d->documentElement->setIdAttribute('id', true);
try {
    $d->getElementById(null);
    echo "fail\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
TypeError
DOMDocument::getElementById(): Argument #1 ($elementId) must be of type string, null given
