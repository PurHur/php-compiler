--TEST--
Language: ReflectionMethod internal parameter counts (#18325, #18338)
--FILE--
<?php
declare(strict_types=1);

echo (new ReflectionMethod('ArrayObject', '__construct'))->getNumberOfParameters(), "\n";
echo (new ReflectionMethod('ArrayObject', '__construct'))->getNumberOfRequiredParameters(), "\n";
echo (new ReflectionParameter(['ArrayObject', '__construct'], 0))->getName(), "\n";
echo (new ReflectionMethod('SplFileObject', 'seek'))->getNumberOfParameters(), "\n";
echo (new ReflectionMethod('SplFileObject', 'seek'))->getNumberOfRequiredParameters(), "\n";
echo (new ReflectionMethod('DateTime', 'format'))->getNumberOfParameters(), "\n";
echo (new ReflectionMethod('DateTime', 'format'))->getNumberOfRequiredParameters(), "\n";
?>
--EXPECT--
3
0
array
1
1
1
1
