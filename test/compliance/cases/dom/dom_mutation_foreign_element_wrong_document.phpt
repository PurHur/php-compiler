--TEST--
DOMNode appendChild/insertBefore/replaceChild foreign Element — Wrong Document Error (#33937)
--FILE--
<?php
$d1 = new DOMDocument();
$d1->loadXML('<r><a/></r>');
$d2 = new DOMDocument();
$d2->loadXML('<r><b/></r>');
try {
    $d1->documentElement->appendChild($d2->documentElement->firstChild);
    echo "append NO_THROW\n";
} catch (DOMException $e) {
    echo 'append code=', $e->getCode(), ' msg=', $e->getMessage(), "\n";
}
$d3 = new DOMDocument();
$d3->loadXML('<r><a/></r>');
$d4 = new DOMDocument();
$d4->loadXML('<r><b/></r>');
try {
    $d3->documentElement->insertBefore($d4->documentElement->firstChild, $d3->documentElement->firstChild);
    echo "insert NO_THROW\n";
} catch (DOMException $e) {
    echo 'insert code=', $e->getCode(), ' msg=', $e->getMessage(), "\n";
}
$d5 = new DOMDocument();
$d5->loadXML('<r><a/></r>');
$d6 = new DOMDocument();
$d6->loadXML('<r><b/></r>');
try {
    $d5->documentElement->replaceChild($d6->documentElement->firstChild, $d5->documentElement->firstChild);
    echo "replace NO_THROW\n";
} catch (DOMException $e) {
    echo 'replace code=', $e->getCode(), ' msg=', $e->getMessage(), "\n";
}
?>
--EXPECT--
append code=4 msg=Wrong Document Error
insert code=4 msg=Wrong Document Error
replace code=4 msg=Wrong Document Error
