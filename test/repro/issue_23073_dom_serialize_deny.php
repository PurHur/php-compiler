<?php

/**
 * Repro for #23073 — DOMDocument/DOMElement serialize deny (php-src ext/dom/node.c).
 */
$dom = new DOMDocument();
$dom->loadXML('<a/>');
foreach (['DOMDocument' => $dom, 'DOMElement' => $dom->documentElement] as $label => $v) {
    try {
        echo $label, ':', serialize($v), "\n";
    } catch (Throwable $e) {
        echo $label, ':', get_class($e), ':', $e->getMessage(), "\n";
    }
}
