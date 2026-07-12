<?php

declare(strict_types=1);

$seek = new ReflectionMethod('SplFileObject', 'seek');
echo 'seek_params=', $seek->getNumberOfParameters(), "\n";
echo 'seek_required_method=', method_exists($seek, 'getNumberOfRequiredParameters') ? 'yes' : 'no', "\n";
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
