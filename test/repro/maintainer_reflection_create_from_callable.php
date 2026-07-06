<?php
declare(strict_types=1);

echo 'createFromCallable=', var_export(method_exists('ReflectionFunction', 'createFromCallable'), true), PHP_EOL;
echo 'createFromClosure=', var_export(method_exists('ReflectionMethod', 'createFromClosure'), true), PHP_EOL;
