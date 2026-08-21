<?php

declare(strict_types=1);

/**
 * Repro #33430 — thin AOT popen/pclose + stream_get_contents must match Zend.
 *
 * php-src: ext/standard/exec.c — PHP_FUNCTION(popen) / PHP_FUNCTION(pclose)
 *          ext/standard/file.c — PHP_FUNCTION(stream_get_contents)
 */
$p = popen('echo hi', 'r');
if (false === $p) {
    echo "open=false\n";
    exit(1);
}
$s = stream_get_contents($p);
$st = pclose($p);
echo 's=', var_export($s, true), "\n";
echo 'st=', var_export($st, true), "\n";
