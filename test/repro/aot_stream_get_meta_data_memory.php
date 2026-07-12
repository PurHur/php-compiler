<?php

declare(strict_types=1);

$f = fopen('php://memory', 'r+');
$meta = stream_get_meta_data($f);
echo is_array($meta) ? "ok\n" : "fail\n";
fclose($f);
