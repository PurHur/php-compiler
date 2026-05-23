<?php declare(strict_types=1);
$a = 41; $b = &$a; $b = 42; echo (string) $a, "\n";
