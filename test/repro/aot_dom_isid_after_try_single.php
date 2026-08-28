<?php
$d = new DOMDocument();
$d->loadXML('<r><e myid="x" class="c">1</e></r>');
$e = $d->documentElement->firstChild;
$e->setIdAttribute('myid', true);
try {
    echo "in\n";
} catch (Throwable $ex) {}
echo 'class_chain=', var_export($e->getAttributeNode('class')->isId(), true), "\n";
echo "done\n";
