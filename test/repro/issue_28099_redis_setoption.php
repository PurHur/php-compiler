<?php
declare(strict_types=1);

/**
 * Repro for #28099 — Redis setOption/getOption + OPT_/SERIALIZER_ declared casing.
 * Run: PHP_COMPILER_PROFILE=8.5 php bin/vm.php test/repro/issue_28099_redis_setoption.php
 */
echo 'extension=', extension_loaded('redis') ? 'true' : 'false', "\n";
echo 'setOption=', method_exists(Redis::class, 'setOption') ? 'true' : 'false', "\n";
echo 'getOption=', method_exists(Redis::class, 'getOption') ? 'true' : 'false', "\n";
$rc = (new ReflectionClass(Redis::class))->getConstants();
echo 'OPT_SERIALIZER_reflection=', array_key_exists('OPT_SERIALIZER', $rc) ? 'true' : 'false', "\n";
echo 'defined=', defined('Redis::OPT_SERIALIZER') ? 'true' : 'false', "\n";
echo 'OPT_SERIALIZER_direct=', (string) Redis::OPT_SERIALIZER, "\n";
echo 'SERIALIZER_PHP_direct=', (string) Redis::SERIALIZER_PHP, "\n";
echo 'MULTI_direct=', (string) Redis::MULTI, "\n";
echo 'PIPELINE_direct=', (string) Redis::PIPELINE, "\n";

$r = new Redis();
$ok = $r->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_PHP);
echo 'set_serializer=', $ok ? 'true' : 'false', "\n";
echo 'get_serializer=', (string) $r->getOption(Redis::OPT_SERIALIZER), "\n";
$ok = $r->setOption(Redis::OPT_PREFIX, 'app:');
echo 'set_prefix=', $ok ? 'true' : 'false', "\n";
echo 'get_prefix=', (string) $r->getOption(Redis::OPT_PREFIX), "\n";
$ok = $r->setOption(Redis::OPT_READ_TIMEOUT, 1.5);
echo 'set_read_timeout=', $ok ? 'true' : 'false', "\n";
echo 'get_read_timeout=', (string) $r->getOption(Redis::OPT_READ_TIMEOUT), "\n";
$ok = $r->setOption(Redis::OPT_TCP_KEEPALIVE, 1);
echo 'set_keepalive=', $ok ? 'true' : 'false', "\n";
echo 'get_keepalive=', (string) $r->getOption(Redis::OPT_TCP_KEEPALIVE), "\n";
