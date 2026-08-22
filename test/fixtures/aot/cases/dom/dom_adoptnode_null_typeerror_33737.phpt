--TEST--
AOT: DOMDocument::adoptNode() variable-null TypeError before NYI (#33737, ext/dom/document.c)
--FILE--
<?php
error_reporting(E_ALL);
$d = new DOMDocument();
$d->loadXML('<r><a id="x"/></r>');
$n = null;
try {
    $d->adoptNode($n);
    echo "adopt_var=fail\n";
} catch (Throwable $ex) {
    echo 'adopt_var=', get_class($ex), ':', $ex->getMessage(), "\n";
}
$miss = $d->getElementById('nope');
try {
    $d->adoptNode($miss);
    echo "adopt_miss=fail\n";
} catch (Throwable $ex) {
    echo 'adopt_miss=', get_class($ex), ':', $ex->getMessage(), "\n";
}
$b = new DOMDocument();
try {
    $b->adoptNode($d->documentElement->firstChild);
    echo "adopt_real=fail\n";
} catch (Throwable $ex) {
    echo 'adopt_real=', get_class($ex), ':', $ex->getMessage(), "\n";
}
?>
--EXPECT--
adopt_var=TypeError:DOMDocument::adoptNode(): Argument #1 ($node) must be of type DOMNode, null given
adopt_miss=TypeError:DOMDocument::adoptNode(): Argument #1 ($node) must be of type DOMNode, null given
adopt_real=Error:Not yet implemented
