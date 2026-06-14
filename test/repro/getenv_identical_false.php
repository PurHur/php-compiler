<?php declare(strict_types=1);
$val = getenv('PHP_COMPILER_M3_SOURCE');
echo ($val === false) ? 'yes' : 'no';
