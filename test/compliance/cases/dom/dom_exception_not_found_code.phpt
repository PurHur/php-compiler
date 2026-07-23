--TEST--
DOMException Not Found Error code is 8 on double-removeChild (#22694, php-src ext/dom/node.c)
--FILE--
<?php
$d = new DOMDocument();
$d->loadXML('<r><a/></r>');
$a = $d->documentElement->firstChild;
$d->documentElement->removeChild($a);
try {
    $d->documentElement->removeChild($a);
    echo "no throw\n";
} catch (DOMException $e) {
    echo 'msg=' . $e->getMessage() . "\n";
    echo 'code=' . $e->getCode() . "\n";
    echo 'prop=' . $e->code . "\n";
}
echo 'const=' . DOM_NOT_FOUND_ERR . "\n";
?>
--EXPECT--
msg=Not Found Error
code=8
prop=8
const=8
