--TEST--
DOMException::$code is public (php-src ext/dom/php_dom.c widens from Exception)
--FILE--
<?php
$doc = new DOMDocument();
try {
    $doc->createElement('123invalid');
} catch (DOMException $e) {
    echo 'message=' . $e->getMessage() . "\n";
    echo 'code=' . $e->code . "\n";
    echo 'getCode=' . $e->getCode() . "\n";
}
?>
--EXPECT--
message=Invalid Character Error
code=5
getCode=5
