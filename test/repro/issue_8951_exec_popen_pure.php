<?php

declare(strict_types=1);

$out = [];
$code = 0;
var_export(exec('echo ok', $out, $code));
echo "\n", implode('|', $out), ':', $code, "\n";
echo shell_exec('echo sh'), "\n";
