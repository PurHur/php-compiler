<?php
declare(strict_types=1);

class UserClass {
    public function f(): void {}
}

$internal = new ReflectionMethod('DateTime', 'format');
echo method_exists($internal, 'isInternal') ? "internal_method_yes\n" : "internal_method_no\n";
echo $internal->isInternal() ? "internal_yes\n" : "internal_no\n";

$user = new ReflectionMethod('UserClass', 'f');
echo $user->isInternal() ? "user_internal_yes\n" : "user_internal_no\n";

$variadic = new ReflectionMethod('ReflectionMethod', 'invoke');
echo method_exists($variadic, 'isVariadic') ? "variadic_method_yes\n" : "variadic_method_no\n";
echo $variadic->isVariadic() ? "variadic_yes\n" : "variadic_no\n";

echo $internal->isVariadic() ? "format_variadic_yes\n" : "format_variadic_no\n";

echo "ok\n";
