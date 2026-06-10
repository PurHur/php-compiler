<?php

declare(strict_types=1);

// Issue #5326: closed Resource must serialize as i:0; (php-src ext/standard/var.c).

$f = fopen('php://memory', 'r+');
fclose($f);
echo serialize($f), "\n";
