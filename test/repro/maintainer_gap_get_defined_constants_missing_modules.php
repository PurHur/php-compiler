<?php

declare(strict_types=1);

$c = get_defined_constants(true);
$modules = ['pcre', 'random', 'xml', 'sockets', 'readline', 'xsl'];
foreach ($modules as $module) {
    echo $module, ': ', isset($c[$module]) ? (string) count($c[$module]) : 'missing', "\n";
}

$pregBucket = 'missing';
if (isset($c['pcre']['PREG_PATTERN_ORDER'])) {
    $pregBucket = 'pcre';
} elseif (isset($c['standard']['PREG_PATTERN_ORDER'])) {
    $pregBucket = 'standard';
}
echo 'preg_pattern_order_bucket=', $pregBucket, "\n";

$ok = true;
foreach ($modules as $module) {
    if (!isset($c[$module]) || 0 === count($c[$module])) {
        $ok = false;
    }
}
if ('pcre' !== $pregBucket) {
    $ok = false;
}
echo $ok ? "ok\n" : "fail\n";
