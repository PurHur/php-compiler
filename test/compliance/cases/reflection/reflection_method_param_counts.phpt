--TEST--
Stdlib: ReflectionMethod::getNumberOfParameters/getNumberOfRequiredParameters() (#18325, ext/reflection/php_reflection.c)
--FILE--
<?php
declare(strict_types=1);

$seek = new ReflectionMethod('SplFileObject', 'seek');
echo 'seek_params=', $seek->getNumberOfParameters(), "\n";
echo 'seek_required=', $seek->getNumberOfRequiredParameters(), "\n";
echo 'seek_match=', count($seek->getParameters()) === $seek->getNumberOfParameters() ? 'yes' : 'no', "\n";

$format = new ReflectionMethod('DateTime', 'format');
echo 'format_params=', $format->getNumberOfParameters(), "\n";
echo 'format_required=', $format->getNumberOfRequiredParameters(), "\n";

class Demo {
    public static function m(int $x, float $y = 1.0): void {}
}
$user = new ReflectionMethod('Demo', 'm');
echo 'user_params=', $user->getNumberOfParameters(), "\n";
echo 'user_required=', $user->getNumberOfRequiredParameters(), "\n";
?>
--EXPECT--
seek_params=1
seek_required=1
seek_match=yes
format_params=1
format_required=1
user_params=2
user_required=1
