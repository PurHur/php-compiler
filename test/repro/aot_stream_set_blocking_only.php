<?php

declare(strict_types=1);

$f = fopen('php://memory', 'r+');
echo stream_set_blocking($f, false) ? "ok\n" : "fail\n";
fclose($f);
