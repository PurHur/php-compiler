<?php
/** Maintainer repro for #11237 — compact() must include superglobals. */
declare(strict_types=1);

$r = compact('_SERVER', '_GET', '_POST');
$ok = array_key_exists('_SERVER', $r)
    && array_key_exists('_GET', $r)
    && array_key_exists('_POST', $r)
    && is_array($r['_SERVER'])
    && is_array($r['_GET'])
    && is_array($r['_POST']);
echo $ok ? "OK\n" : 'FAIL keys='.var_export(array_keys($r), true)."\n";
exit($ok ? 0 : 1);
