--TEST--
DOMNode::insertBefore foreign firstChild PropertyFetch args — Wrong Document Error (#22710)
--FILE--
<?php
// Exact issue repro: both args are chained PropertyFetches (must not collapse onto one temp).
$d1 = new DOMDocument();
$d1->loadXML('<r><a/></r>');
$d2 = new DOMDocument();
$d2->loadXML('<r><b/></r>');
try {
    $d1->documentElement->insertBefore($d2->documentElement->firstChild, $d1->documentElement->firstChild);
    echo "NO_THROW\n";
} catch (DOMException $e) {
    echo 'code=', $e->getCode(), ' msg=', $e->getMessage(), "\n";
}

// Saved locals (control) — same Wrong Document Error when temps are distinct.
$d3 = new DOMDocument();
$d3->loadXML('<r><a/></r>');
$d4 = new DOMDocument();
$d4->loadXML('<r><b/></r>');
$new = $d4->documentElement->firstChild;
$ref = $d3->documentElement->firstChild;
try {
    $d3->documentElement->insertBefore($new, $ref);
    echo "locals NO_THROW\n";
} catch (DOMException $e) {
    echo 'locals code=', $e->getCode(), ' msg=', $e->getMessage(), "\n";
}

// Dual PropertyFetch FuncCall args must stay distinct (compiler ARG_SEND; #22710).
function peek_nodes($x, $y) {
    echo 'peek=', $x->nodeName, ',', $y->nodeName, ',same=', ($x === $y ? '1' : '0'), "\n";
}
$d5 = new DOMDocument();
$d5->loadXML('<r><a/></r>');
$d6 = new DOMDocument();
$d6->loadXML('<r><b/></r>');
peek_nodes($d6->documentElement->firstChild, $d5->documentElement->firstChild);
?>
--EXPECT--
code=4 msg=Wrong Document Error
locals code=4 msg=Wrong Document Error
peek=b,a,same=0
