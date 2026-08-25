<?php
/**
 * #34716 — AOT: DOMNode::appendChild() non-DOMNode scalars must TypeError like Zend
 * (php-src ext/dom/php_dom.stub.php / zend_API Z_PARAM_OBJ_OF_CLASS), not
 * LogicException at compile time or SIGSEGV on __value__readObject.
 */
$d = new DOMDocument();
$e = $d->appendChild($d->createElement('e'));

foreach (
    [
        'int' => 1,
        'bool' => true,
        'float' => 1.5,
        'string' => 'x',
        'array' => [],
        'null' => null,
    ] as $label => $v
) {
    try {
        $e->appendChild($v);
        echo $label, "=fail\n";
    } catch (Throwable $ex) {
        echo $label, '=', get_class($ex), ':', $ex->getMessage(), "\n";
    }
}

// Variable-null peer (#33716) still catchable.
$n = null;
try {
    $e->appendChild($n);
    echo "var_null=fail\n";
} catch (Throwable $ex) {
    echo 'var_null=', get_class($ex), ':', $ex->getMessage(), "\n";
}

echo "ok\n";
