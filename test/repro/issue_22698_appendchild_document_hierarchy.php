<?php
/**
 * Repro #22698 — appendChild(DOMDocument) must be DOMException Hierarchy Request Error
 * (code 3), not TypeError (php-src ext/dom/node.c).
 */
$d = new DOMDocument();
$e = $d->createElement('a');
$d->appendChild($e);
echo 'instanceof DOMNode=', $d instanceof DOMNode ? 'yes' : 'no', "\n";
try {
    $e->appendChild($d);
    echo "OK\n";
} catch (Throwable $ex) {
    echo get_class($ex), ': ', $ex->getMessage(), "\n";
    if ($ex instanceof DOMException) {
        echo 'code=', $ex->getCode(), "\n";
    }
}
echo 'DOM_HIERARCHY_REQUEST_ERR=', DOM_HIERARCHY_REQUEST_ERR, "\n";
