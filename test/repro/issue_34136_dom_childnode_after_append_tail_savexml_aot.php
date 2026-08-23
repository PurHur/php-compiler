<?php
/**
 * AOT: ChildNode::after() append-tail updates LiveSlots but saveXML drops sibling.
 * Zend/VM/JIT: <r><b/><c/></r> — AOT (pre-fix): <r><b/></r>
 */
$d = new DOMDocument();
$d->loadXML('<r><b/></r>');
$b = $d->documentElement->firstChild;
$b->after($d->createElement('c'));
echo $d->saveXML($d->documentElement), "\n";
