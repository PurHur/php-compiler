<?php

declare(strict_types=1);

$h = fopen('php://memory', 'r+');
fclose($h);
echo gettype($h), "\n";
echo get_debug_type($h), "\n";
