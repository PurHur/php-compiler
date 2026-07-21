<?php

/**
 * DOMException::$code is public in php-src (ext/dom/php_dom.c),
 * widening from Exception's protected $code.
 *
 * Zend 8.2+: prints "Code: 5"
 * Bug:       Fatal error: Cannot access protected property ::$code
 */
$doc = new DOMDocument();
try {
    $doc->createElement('123invalid');
} catch (DOMException $e) {
    echo 'Message: ' . $e->getMessage() . "\n";
    echo 'Code: ' . $e->code . "\n";
}
