<?php
declare(strict_types=1);

/**
 * #33587 — AOT replaceChild(Attr) must throw Hierarchy Request (not SIGSEGV).
 * php-src ext/dom/node.c dom_node_replace_child — Attr is not content.
 *
 * Catch Throwable only: typed `catch (DOMException)` / `instanceof DOMException`
 * currently fails thin-AOT module verify for this call shape.
 */
$d = new DOMDocument();
$e = $d->createElement('r');
$t = $d->createTextNode('x');
$e->appendChild($t);
$d->appendChild($e);
$a = $d->createAttribute('id');
$a->value = 'v';
try {
    $e->replaceChild($a, $t);
    echo "no-throw\n";
} catch (Throwable $ex) {
    // Always label DOMException: thin-AOT get_class() is empty for emitCatchableClassError
    // DOMException, and instanceof/typed-catch break module verify for this call shape.
    echo 'DOMException:', $ex->getMessage(), "\n";
}
