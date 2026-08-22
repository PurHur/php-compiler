<?php
/** Repro #33746: AOT ChildNode after/before/replaceWith null must TypeError. */
error_reporting(E_ALL);
$d = new DOMDocument();
$d->loadXML('<r><a/></r>');
$el = $d->documentElement->firstChild;
$n = null;
try {
    $el->after($n);
    echo "after=fail\n";
} catch (Throwable $ex) {
    echo 'after=', get_class($ex), ':', $ex->getMessage(), "\n";
}
try {
    $el->before($n);
    echo "before=fail\n";
} catch (Throwable $ex) {
    echo 'before=', get_class($ex), ':', $ex->getMessage(), "\n";
}
try {
    $el->replaceWith($n);
    echo "replaceWith=fail\n";
} catch (Throwable $ex) {
    echo 'replaceWith=', get_class($ex), ':', $ex->getMessage(), "\n";
}
$miss = $d->getElementById('nope');
try {
    $el->after($miss);
    echo "id=fail\n";
} catch (Throwable $ex) {
    echo 'id=', get_class($ex), ':', $ex->getMessage(), "\n";
}
