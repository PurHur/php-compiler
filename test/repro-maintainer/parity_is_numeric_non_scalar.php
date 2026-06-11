<?php

declare(strict_types=1);

echo is_numeric([1]) ? 'true' : 'false', "\n";
echo is_numeric(new stdClass()) ? 'true' : 'false', "\n";
$f = fopen('php://memory', 'r+');
echo is_numeric($f) ? 'true' : 'false', "\n";
fclose($f);
