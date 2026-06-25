<?php

declare(strict_types=1);

$fp = fopen('php://memory', 'r+');
echo var_export($fp, true), "\n";
