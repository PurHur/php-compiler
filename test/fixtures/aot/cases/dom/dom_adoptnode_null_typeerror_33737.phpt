--TEST--
AOT: DOMDocument::adoptNode() variable-null TypeError before NYI (#33737, ext/dom/document.c)
--FILE--
<?php
error_reporting(E_ALL);
$d = new DOMDocument();
$d->loadXML('<r id="x"/>');
$n = null;
try {
    $d->adoptNode($n);
    echo "var=fail\n";
} catch (Throwable $ex) {
    echo 'var=', get_class($ex), ':', $ex->getMessage(), "\n";
}
$miss = $d->getElementById('nope');
try {
    $d->adoptNode($miss);
    echo "id=fail\n";
} catch (Throwable $ex) {
    echo 'id=', get_class($ex), ':', $ex->getMessage(), "\n";
}
try {
    $d->adoptNode($d->documentElement);
    echo "real=ok\n";
} catch (Throwable $ex) {
    echo 'real=', get_class($ex), ':', $ex->getMessage(), "\n";
}
--EXPECT--
var=TypeError:DOMDocument::adoptNode(): Argument #1 ($node) must be of type DOMNode, null given
id=TypeError:DOMDocument::adoptNode(): Argument #1 ($node) must be of type DOMNode, null given
real=Error:Not yet implemented
