<?php

declare(strict_types=1);

$fp = fopen('php://memory', 'r+');
echo strval($fp), "\n";
echo (string) $fp, "\n";
