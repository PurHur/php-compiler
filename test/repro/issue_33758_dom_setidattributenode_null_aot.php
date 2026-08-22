<?php
/** Repro #33758: AOT setIdAttributeNode(null) TypeError (peer #33753). */
error_reporting(E_ALL);
$d = new DOMDocument();
$e = $d->createElement('e');
$n = null;
try {
    $e->setIdAttributeNode($n, true);
    echo "var=fail\n";
} catch (Throwable $ex) {
    echo 'var=', get_class($ex), ':', $ex->getMessage(), "\n";
}
try {
    $e->setIdAttributeNode(null, true);
    echo "lit=fail\n";
} catch (Throwable $ex) {
    echo 'lit=', get_class($ex), ':', $ex->getMessage(), "\n";
}
