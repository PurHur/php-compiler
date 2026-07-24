--TEST--
DOMNode::insertBefore($n, $n) throws Error previous sibling (#22686, php-src ext/dom/node.c)
--FILE--
<?php
$d = new DOMDocument();
$d->loadXML('<r><a/><b/></r>');
$a = $d->documentElement->firstChild;
try {
    $d->documentElement->insertBefore($a, $a);
    echo "ok\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
echo 'still_child=', ($a->parentNode === $d->documentElement ? '1' : '0'), "\n";
echo 'len=', $d->documentElement->childNodes->length, "\n";
?>
--EXPECT--
Error:Cannot add newnode as the previous sibling of refnode
still_child=1
len=2
