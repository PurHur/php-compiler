<?php

declare(strict_types=1);

$tmp = sys_get_temp_dir().'/phpc_rp_33432_'.getmypid();
file_put_contents($tmp, 'x');
$abs = realpath($tmp);
$root = realpath('/tmp');
$missing = realpath($tmp.'/no-such-33432');
$tmpOk = is_string($abs) && str_contains($abs, 'phpc_rp_33432_');
$rootOk = is_string($root) && ($root === '/tmp' || str_starts_with($root, '/'));
$missingOk = $missing === false || $missing === '';
@unlink($tmp);
echo ($tmpOk && $rootOk && $missingOk)
    ? "ok\n"
    : ('fail tmp='.var_export($abs, true).' root='.var_export($root, true).' missing='.var_export($missing, true)."\n");
