<?php
$d = new DOMDocument();
$d->loadXML('<r/>');
$x = new DOMXPath($d);
try {
    echo serialize($x), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    unserialize('O:8:"DOMXPath":0:{}');
    echo "unserialize:ok\n";
} catch (Throwable $e) {
    echo 'unserialize:', get_class($e), ':', $e->getMessage(), "\n";
}
