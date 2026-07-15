--TEST--
DOM: dom_import_simplexml(null) TypeError not ValueError (#19026, ext/dom/php_dom.c)
--FILE--
<?php
try {
    dom_import_simplexml(null);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
dom_import_simplexml(): Argument #1 ($node) must be of type object, null given
