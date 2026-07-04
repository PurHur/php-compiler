<?php
declare(strict_types=1);

require_once __DIR__.'/../../vendor/autoload.php';

use PHPCompiler\ext\intl\VmGrapheme;

echo VmGrapheme::strimwidth('こんにちは', 0, 3, '...'), "\n";
echo VmGrapheme::strimwidth('hello', 0, 10), "\n";
