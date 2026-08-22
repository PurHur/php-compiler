<?php
/** Repro #33753: AOT setAttributeNode(null)/setAttributeNodeNS(null)/removeAttributeNode(null) TypeError. */
error_reporting(E_ALL);
$d = new DOMDocument();
$e = $d->createElement('e');
$n = null;
try {
    $e->setAttributeNode($n);
    echo "set=fail\n";
} catch (Throwable $ex) {
    echo 'set=', get_class($ex), ':', $ex->getMessage(), "\n";
}
try {
    $e->setAttributeNodeNS($n);
    echo "setns=fail\n";
} catch (Throwable $ex) {
    echo 'setns=', get_class($ex), ':', $ex->getMessage(), "\n";
}
try {
    $e->removeAttributeNode($n);
    echo "rm=fail\n";
} catch (Throwable $ex) {
    echo 'rm=', get_class($ex), ':', $ex->getMessage(), "\n";
}
try {
    $e->setAttributeNode(null);
    echo "set_lit=fail\n";
} catch (Throwable $ex) {
    echo 'set_lit=', get_class($ex), ':', $ex->getMessage(), "\n";
}
