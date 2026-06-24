<?php

declare(strict_types=1);

$src = fopen('/etc/hosts', 'r');
$dst = fopen('php://memory', 'w+');
$n = stream_copy_to_stream($src, $dst);
var_export($n > 0);
echo "\n";
fclose($src);
fclose($dst);
