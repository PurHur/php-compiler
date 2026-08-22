<?php
/**
 * AOT: TypeError from DOMNode::appendChild(null) inside a closure must be catchable
 * by an outer try/catch (Zend / php-src ext/dom/node.c Z_PARAM_OBJ_OF_CLASS).
 *
 * Direct try/catch in {main} already worked (#33716). Closure bodies had no local
 * try handler, so ExceptionBridge::emitTypeErrorAndAbort aborted in-place (#33971).
 */
declare(strict_types=1);

$doc = new DOMDocument();
$el = $doc->createElement('a');
$doc->appendChild($el);
$null = null;
$fn = function () use ($el, $null) {
    $el->appendChild($null);
};
try {
    $fn();
    echo "NO_THROW\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
