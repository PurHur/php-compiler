<?php
/**
 * Parity: inline array literal dim-fetch — offset read (#16462, Zend/zend_compile.c).
 */
declare(strict_types=1);

$v = (['a' => 1])['a'];
if (1 !== $v) {
    fwrite(STDERR, "expected 1, got " . var_export($v, true) . PHP_EOL);
    exit(1);
}

if (!isset(['a' => 1]['a'])) {
    fwrite(STDERR, "isset inline literal dim-fetch expected true" . PHP_EOL);
    exit(1);
}

if (empty(['a' => 1]['a'])) {
    fwrite(STDERR, "empty inline literal dim-fetch expected false" . PHP_EOL);
    exit(1);
}

echo "ok\n";
