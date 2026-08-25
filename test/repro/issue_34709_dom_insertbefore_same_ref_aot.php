<?php
/** AOT: DOMNode::insertBefore($n,$n) throws Zend Error (#34709 / re-#22686). */
$d = new DOMDocument();
$d->loadXML('<r><a/><b/></r>');
$b = $d->documentElement->lastChild;
try {
    $d->documentElement->insertBefore($b, $b);
    echo "ok\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
