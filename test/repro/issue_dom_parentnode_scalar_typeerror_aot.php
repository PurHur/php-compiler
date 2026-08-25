<?php
/**
 * #34729 — AOT: ParentNode::append scalars must TypeError like Zend
 * (php-src ext/dom/php_dom.stub.php DOMNode|string), not silent success or SIGSEGV.
 * Peer #33741 covered null only.
 */
$d = new DOMDocument();
$e = $d->appendChild($d->createElement('e'));

foreach (
    [
        'int' => 1,
        'bool' => true,
        'float' => 1.5,
        'array' => [],
        'null' => null,
    ] as $label => $v
) {
    try {
        $e->append($v);
        echo 'append_', $label, "=fail\n";
    } catch (Throwable $ex) {
        echo 'append_', $label, '=', get_class($ex), ':', $ex->getMessage(), "\n";
    }
}

try {
    $e->append('text');
    echo 'append_str=ok:', $e->textContent, "\n";
} catch (Throwable $ex) {
    echo 'append_str=', get_class($ex), ':', $ex->getMessage(), "\n";
}
