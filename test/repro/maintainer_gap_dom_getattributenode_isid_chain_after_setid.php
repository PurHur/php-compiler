<?php
/**
 * After setIdAttribute / DTD ID typing, chained getAttributeNode()->isId()
 * must not see a null temp (re-#25841 residual — FuncCall-arg path was fixed;
 * bare MethodCall chain after ID bookkeeping still red).
 *
 *   php test/repro/maintainer_gap_dom_getattributenode_isid_chain_after_setid.php
 *   php bin/vm.php test/repro/maintainer_gap_dom_getattributenode_isid_chain_after_setid.php
 */

$d = new DOMDocument();
$d->loadXML('<r><e myid="x" class="c">1</e></r>');
$e = $d->documentElement->firstChild;
$e->setIdAttribute('myid', true);

try {
    echo 'id_chain=', var_export($e->getAttributeNode('myid')->isId(), true), "\n";
} catch (Throwable $ex) {
    echo 'id_chain ERR ', $ex->getMessage(), "\n";
}

try {
    echo 'class_chain=', var_export($e->getAttributeNode('class')->isId(), true), "\n";
} catch (Throwable $ex) {
    echo 'class_chain ERR ', $ex->getMessage(), "\n";
}

$a = $e->getAttributeNode('myid');
echo 'id_assigned=', var_export($a->isId(), true), "\n";

// DTD ATTLIST ID — same chain shape on the source document
$src = new DOMDocument();
$src->loadXML('<!DOCTYPE x [<!ATTLIST c id ID #IMPLIED>]><r><c id="t">x</c></r>');
$el = $src->documentElement->firstChild;
try {
    echo 'dtd_chain=', var_export($el->getAttributeNode('id')->isId(), true), "\n";
} catch (Throwable $ex) {
    echo 'dtd_chain ERR ', $ex->getMessage(), "\n";
}
$an = $el->getAttributeNode('id');
echo 'dtd_assigned=', var_export($an->isId(), true), "\n";
