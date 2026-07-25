<?php

declare(strict_types=1);

// Repro #22990 — AOT pack() must compile and run (Zend-matching bytes).
$b = pack('N', 1234);
echo 'packed ', strlen($b), ' bytes', PHP_EOL;
echo bin2hex($b), PHP_EOL;
