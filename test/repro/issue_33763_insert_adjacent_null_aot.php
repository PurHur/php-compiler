<?php
declare(strict_types=1);

$d = new DOMDocument();
$d->loadXML('<r><a/></r>');
$el = $d->documentElement;
$n = null;
try {
    var_export($el->insertAdjacentElement('beforeend', $n));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
