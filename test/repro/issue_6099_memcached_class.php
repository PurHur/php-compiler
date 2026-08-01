<?php
/**
 * Repro for #6099 — Memcached class registration + set/get when daemon up.
 *
 * Under reference profile (no PHP_COMPILER_PROFILE): class_exists false.
 * Under PROFILE=8.4: class exists; set/get need memcached on 11211.
 */
declare(strict_types=1);

var_export(class_exists('Memcached'));
echo "\n";
if (!class_exists('Memcached')) {
    exit(0);
}
$m = new Memcached();
$m->addServer('127.0.0.1', 11211);
$m->set('k', 'v', 60);
$got = $m->get('k');
echo false === $got ? "false\n" : ($got."\n");
echo 'code=', $m->getResultCode(), "\n";
