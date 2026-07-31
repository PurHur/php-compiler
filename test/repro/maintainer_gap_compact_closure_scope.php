<?php
declare(strict_types=1);
// Repro #25898 — compact() must not resolve outer-scope vars from a closure
$b = 'OUTER';
$r = (function () {
    $a = 1;

    return compact('a', 'b');
})();
echo 'keys='.implode(',', array_keys($r))."\n";
echo 'has_b='.(array_key_exists('b', $r) ? '1' : '0')."\n";
if (array_key_exists('b', $r)) {
    echo 'b='.var_export($r['b'], true)."\n";
}
