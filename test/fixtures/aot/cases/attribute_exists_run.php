<?php
// AOT runfile for attribute_exists.phpt (#16844).
declare(strict_types=1);

#[\AllowDynamicProperties]
class Demo {}

echo (function_exists('attribute_exists') ? '1' : '0');
echo (attribute_exists('AllowDynamicProperties', Demo::class) ? '1' : '0');
echo (attribute_exists('NoSuchAttribute', Demo::class) ? '1' : '0');
echo (attribute_exists('AllowDynamicProperties', 'NoSuchClass') ? '1' : '0');
echo "\n";
