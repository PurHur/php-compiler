<?php

declare(strict_types=1);

// Maintainer gap: debug_zval_dump() stream resource line format (#18419).
$h = fopen('php://memory', 'r+');
debug_zval_dump($h);
$h2 = $h;
debug_zval_dump($h);
fclose($h);
debug_zval_dump($h);
