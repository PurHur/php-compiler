<?php
declare(strict_types=1);
$t = time();
$p = pi();
echo (string) ($t > 0 ? 1 : 0);
echo (string) ($p > 3.0 && $p < 4.0 ? 1 : 0);
