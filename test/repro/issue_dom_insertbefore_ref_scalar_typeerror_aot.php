<?php
/**
 * #34729 — AOT: insertBefore($node, $scalar) must TypeError (?DOMNode), not abort.
 * Null refChild still appends (php-src ext/dom/node.c).
 */
$d = new DOMDocument();
$e = $d->appendChild($d->createElement('e'));
$e->appendChild($d->createElement('c'));
$n = $d->createElement('n');

foreach (['int' => 1, 'array' => [], 'str' => 'x'] as $label => $v) {
    try {
        $e->insertBefore($n, $v);
        echo 'ib_', $label, "=fail\n";
    } catch (Throwable $ex) {
        echo 'ib_', $label, '=', get_class($ex), ':', $ex->getMessage(), "\n";
    }
}

$ref = null;
try {
    $e->insertBefore($d->createElement('z'), $ref);
    echo "ib_var_null=ok\n";
} catch (Throwable $ex) {
    echo 'ib_var_null=', get_class($ex), ':', $ex->getMessage(), "\n";
}
