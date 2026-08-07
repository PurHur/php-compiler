<?php
declare(strict_types=1);

/**
 * Repro for #27874 — Memcached depth methods when memcached advertised.
 * Run: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_27874_memcached_depth.php
 *
 * Optional live daemon on 127.0.0.1:11211 — add/getMulti/incr smoke; otherwise skip.
 */
echo 'ext=', extension_loaded('memcached') ? '1' : '0', "\n";
echo 'class=', class_exists('Memcached') ? '1' : '0', "\n";
$ref = new ReflectionClass('Memcached');
foreach (['get','set','delete','add','getMulti','setMulti','deleteMulti','increment','decrement','cas','flush','append','replace','touch','prepend'] as $meth) {
    echo $meth, '=', $ref->hasMethod($meth) ? '1' : '0', "\n";
}

$probe = @fsockopen('127.0.0.1', 11211, $errno, $errstr, 0.2);
if (false === $probe) {
    echo "live_skipped=1\n";
    exit(0);
}
fclose($probe);

$m = new Memcached();
$m->addServer('127.0.0.1', 11211);
$prefix = 'phpc27874_'.getmypid().'_';
$key = $prefix.'n';
$m->delete($key);
echo 'add1=', $m->add($key, '10', 60) ? '1' : '0', "\n";
echo 'add2=', $m->add($key, 'x', 60) ? '1' : '0', "\n";
$multi = $m->getMulti([$key, $prefix.'missing']);
echo 'getMulti=', is_array($multi) && isset($multi[$key]) && $multi[$key] === '10' ? '1' : '0', "\n";
$inc = $m->increment($key, 5);
echo 'incr=', (false !== $inc && (int) $inc === 15) ? '1' : '0', "\n";
$m->delete($key);
