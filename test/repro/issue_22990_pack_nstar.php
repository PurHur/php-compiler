<?php

declare(strict_types=1);

// Repro #22990 companion — n* multi-arg AOT pack.
echo bin2hex(pack('n*', 1, 2)), PHP_EOL;
