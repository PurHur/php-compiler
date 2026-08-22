--TEST--
AOT: isEqualNode/compareDocumentPosition variable-null — false / TypeError, not SIGSEGV (#33733, ext/dom/php_dom.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL);
$d = new DOMDocument();
$d->loadXML('<r><a/></r>');
$e = $d->documentElement;
$n = null;
echo 'eq_self=', (int) $e->isEqualNode($e), "\n";
echo 'eq_var=', (int) $e->isEqualNode($n), "\n";
$miss = $d->getElementById('nope');
echo 'eq_miss=', (int) $e->isEqualNode($miss), "\n";
try {
    $e->compareDocumentPosition($n);
    echo "cdp_var=fail\n";
} catch (Throwable $ex) {
    echo 'cdp_var=', get_class($ex), ':', $ex->getMessage(), "\n";
}
try {
    $e->compareDocumentPosition($miss);
    echo "cdp_miss=fail\n";
} catch (Throwable $ex) {
    echo 'cdp_miss=', get_class($ex), ':', $ex->getMessage(), "\n";
}
?>
--EXPECT--
eq_self=1
eq_var=0
eq_miss=0
cdp_var=TypeError:DOMNode::compareDocumentPosition(): Argument #1 ($other) must be of type DOMNode, null given
cdp_miss=TypeError:DOMNode::compareDocumentPosition(): Argument #1 ($other) must be of type DOMNode, null given
