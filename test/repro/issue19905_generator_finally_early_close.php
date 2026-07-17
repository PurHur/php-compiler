<?php
/**
 * Issue #19905 — generator try/finally on early close (foreach break / unset).
 * Zend zend_generator_dtor_storage runs pending finally when the Generator is destroyed.
 */
declare(strict_types=1);

function gen() {
    try {
        yield 1;
        yield 2;
    } finally {
        echo "FIN\n";
    }
}

foreach (gen() as $v) {
    echo "V=$v\n";
    break;
}

$g = gen();
$g->current();
unset($g);
echo "after unset\n";
