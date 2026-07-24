--TEST--
DOMNode::replaceChild foreign firstChild PropertyFetch args — Wrong Document Error (#22711, #22710)
--FILE--
<?php
$d1 = new DOMDocument();
$d1->loadXML('<r><a/></r>');
$d2 = new DOMDocument();
$d2->loadXML('<r><b/></r>');
try {
    $d1->documentElement->replaceChild($d2->documentElement->firstChild, $d1->documentElement->firstChild);
    echo "NO_THROW\n";
} catch (DOMException $e) {
    echo 'code=', $e->getCode(), ' msg=', $e->getMessage(), "\n";
}
?>
--EXPECT--
code=4 msg=Wrong Document Error
