--TEST--
AOT: attribute_exists() class attribute probe (#6468)
--FILE--
<?php
declare(strict_types=1);

#[\AllowDynamicProperties]
class Demo {}

echo function_exists('attribute_exists') ? "1\n" : "0\n";
echo attribute_exists(Demo::class, AllowDynamicProperties::class) ? "1\n" : "0\n";
echo attribute_exists(Demo::class, 'NoSuchAttribute') ? "1\n" : "0\n";
echo attribute_exists('NoSuchClass', AllowDynamicProperties::class) ? "1\n" : "0\n";
--EXPECT--
1
1
0
0
--EXPECT_EXIT--
0
