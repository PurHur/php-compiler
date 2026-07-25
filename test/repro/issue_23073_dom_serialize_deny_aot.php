<?php

$dom = new DOMDocument();
$dom->loadXML('<a/>');
try {
    echo serialize($dom), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    echo serialize($dom->documentElement), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
