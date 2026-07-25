<?php
// repro #23167 — InternalIterator @not-serializable
$doc = new DOMDocument();
$doc->loadXML('<r><a/></r>');
$it = $doc->getElementsByTagName('*')->getIterator();
echo get_class($it), "\n";
try {
    echo serialize($it), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    unserialize('O:16:"InternalIterator":0:{}');
    echo "unserialize:no-throw\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
