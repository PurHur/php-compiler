<?php
/**
 * Minimal AOT repro: DOMAttr::isId() after try/catch stops the program (#25841).
 *
 *   php bin/vm.php test/repro/aot_dom_isid_after_try_min.php
 *   php bin/compile.php -o /tmp/out test/repro/aot_dom_isid_after_try_min.php && /tmp/out
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
echo "done\n";
