<?php

declare(strict_types=1);

/**
 * Maintainer repro: iconv() UTF-8//IGNORE strips invalid bytes (#11678).
 */

$s = iconv('UTF-8', 'UTF-8//IGNORE', "a\xc0b");
echo 'hex=', bin2hex($s), "\n";
echo 'len=', strlen($s), "\n";
