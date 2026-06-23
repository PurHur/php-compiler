<?php

declare(strict_types=1);

$h = fopen('php://memory', 'w+');
fwrite($h, 'abc');
var_export(fflush($h));
echo "\n";
fclose($h);
