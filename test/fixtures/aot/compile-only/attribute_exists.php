<?php
// Compile-only (#16844): attribute_exists() Zend operand order for native AOT.
declare(strict_types=1);

#[\AllowDynamicProperties]
class Demo {}

echo (function_exists('attribute_exists') ? '1' : '0');
echo (attribute_exists('AllowDynamicProperties', Demo::class) ? '1' : '0');
echo (attribute_exists('NoSuchAttribute', Demo::class) ? '1' : '0');
echo "\n";
