<?php
// SimpleXMLElement::addChild/addAttribute(null): Zend Deprecated + ValueError empty name (sxe.c).
error_reporting(E_ALL);
$x = new SimpleXMLElement('<r/>');
try {
    $x->addChild(null);
    echo "addChild ok\n";
} catch (Throwable $e) {
    echo 'addChild ', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    $x->addAttribute(null, 'v');
    echo "addAttribute ok\n";
} catch (Throwable $e) {
    echo 'addAttribute ', get_class($e), ': ', $e->getMessage(), "\n";
}
