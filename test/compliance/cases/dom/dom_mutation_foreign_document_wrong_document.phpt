--TEST--
DOMNode appendChild/insertBefore/replaceChild foreign DOMDocument — Wrong Document Error (#30271)
--FILE--
<?php
$d = new DOMDocument();
$d->loadXML('<r><a/></r>');
$other = new DOMDocument();

try {
    $d->documentElement->appendChild($other);
    echo "append NO_THROW\n";
} catch (DOMException $e) {
    echo 'append code=', $e->getCode(), ' msg=', $e->getMessage(), "\n";
}

try {
    $d->documentElement->insertBefore($other, $d->documentElement->firstChild);
    echo "insertBefore NO_THROW\n";
} catch (DOMException $e) {
    echo 'insertBefore code=', $e->getCode(), ' msg=', $e->getMessage(), "\n";
}

try {
    $d->documentElement->replaceChild($other, $d->documentElement->firstChild);
    echo "replaceChild NO_THROW\n";
} catch (DOMException $e) {
    echo 'replaceChild code=', $e->getCode(), ' msg=', $e->getMessage(), "\n";
}

// Same-document document-as-child stays Hierarchy Request (#22698).
try {
    $d->documentElement->appendChild($d);
    echo "same append NO_THROW\n";
} catch (DOMException $e) {
    echo 'same append code=', $e->getCode(), ' msg=', $e->getMessage(), "\n";
}

echo 'WRONG_DOCUMENT_ERR=', DOM_WRONG_DOCUMENT_ERR, ' HIERARCHY_REQUEST_ERR=', DOM_HIERARCHY_REQUEST_ERR, "\n";
?>
--EXPECT--
append code=4 msg=Wrong Document Error
insertBefore code=4 msg=Wrong Document Error
replaceChild code=4 msg=Wrong Document Error
same append code=3 msg=Hierarchy Request Error
WRONG_DOCUMENT_ERR=4 HIERARCHY_REQUEST_ERR=3
