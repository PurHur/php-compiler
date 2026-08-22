<?php
/** Repro #33741: AOT ParentNode append/prepend/replaceChildren null must TypeError. */
error_reporting(E_ALL);
$d = new DOMDocument();
$d->loadXML('<r><a/></r>');
$el = $d->documentElement;
$n = null;
try {
    $el->append($n);
    echo "append=fail\n";
} catch (Throwable $ex) {
    echo 'append=', get_class($ex), ':', $ex->getMessage(), "\n";
}
try {
    $el->prepend($n);
    echo "prepend=fail\n";
} catch (Throwable $ex) {
    echo 'prepend=', get_class($ex), ':', $ex->getMessage(), "\n";
}
try {
    $el->replaceChildren($n);
    echo "replaceChildren=fail\n";
} catch (Throwable $ex) {
    echo 'replaceChildren=', get_class($ex), ':', $ex->getMessage(), "\n";
}
$miss = $d->getElementById('nope');
try {
    $el->append($miss);
    echo "id=fail\n";
} catch (Throwable $ex) {
    echo 'id=', get_class($ex), ':', $ex->getMessage(), "\n";
}
