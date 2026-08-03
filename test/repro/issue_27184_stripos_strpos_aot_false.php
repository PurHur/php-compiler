<?php
declare(strict_types=1);
// Repro #27184 — AOT strpos/stripos/strrpos/strripos miss must be boolean false, not int 0
$cases = [
    'stripos_miss' => stripos('Hello', 'x'),
    'stripos_hit' => stripos('Hello World', 'WORLD'),
    'stripos_hit0' => stripos('Hello', 'h'),
    'strpos_miss' => strpos('Hello', 'x'),
    'strpos_hit0' => strpos('Hello', 'H'),
    'strripos_miss' => strripos('Hello', 'x'),
    'strrpos_miss' => strrpos('Hello', 'x'),
];
foreach ($cases as $name => $v) {
    echo $name, '=', var_export($v, true), ' type=', gettype($v), "\n";
}
echo 'miss_identical_false=', (stripos('Hello', 'x') === false ? 'Y' : 'N'), "\n";
echo 'hit0_identical_0=', (strpos('Hello', 'H') === 0 ? 'Y' : 'N'), "\n";
echo 'hit0_identical_false=', (strpos('Hello', 'H') === false ? 'Y' : 'N'), "\n";
