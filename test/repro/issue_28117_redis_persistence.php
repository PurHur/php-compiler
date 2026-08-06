<?php
declare(strict_types=1);

/**
 * Repro for #28117 — Redis save/bgsave/lastSave/wait/waitaof/bgrewriteaof.
 * Run: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_28117_redis_persistence.php
 *
 * Live Redis optional via PHP_COMPILER_REDIS_HOST; offline path asserts method_exists.
 */
echo 'Redis=', class_exists('Redis') ? '1' : '0', "\n";
foreach (['save', 'bgsave', 'lastSave', 'wait', 'waitaof', 'bgrewriteaof'] as $m) {
    echo $m, '=', method_exists('Redis', $m) ? '1' : '0', "\n";
}

$host = getenv('PHP_COMPILER_REDIS_HOST');
if (false === $host || '' === $host) {
    echo "live_skip=1\n";
    exit(0);
}

$port = (int) (getenv('PHP_COMPILER_REDIS_PORT') ?: 6379);
$r = new Redis();
$r->connect($host, $port, 2.0);
echo 'save=', $r->save() ? '1' : '0', "\n";
echo 'bgsave=', $r->bgsave() ? '1' : '0', "\n";
$ls = $r->lastSave();
echo 'lastSave=', is_int($ls) ? '1' : '0', "\n";
$w = $r->wait(0, 100);
echo 'wait=', is_int($w) ? '1' : '0', "\n";
$wa = @$r->waitaof(0, 0, 100);
echo 'waitaof=', (is_array($wa) || false === $wa) ? '1' : '0', "\n";
echo 'bgrewriteaof=', $r->bgrewriteaof() ? '1' : '0', "\n";
