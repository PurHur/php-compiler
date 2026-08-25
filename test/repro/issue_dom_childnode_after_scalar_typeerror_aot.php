<?php
/**
 * #34729 — AOT: ChildNode::after(scalar) TypeError (DOMNode|string), peer ParentNode append.
 */
$d = new DOMDocument();
$e = $d->appendChild($d->createElement('e'));
$c = $e->appendChild($d->createElement('c'));

foreach (['int' => 1, 'array' => [], 'null' => null] as $label => $v) {
    try {
        $c->after($v);
        echo 'after_', $label, "=fail\n";
    } catch (Throwable $ex) {
        echo 'after_', $label, '=', get_class($ex), ':', $ex->getMessage(), "\n";
    }
}
