--TEST--
ReflectionMethod::isInternal()/isVariadic() (#18228, ext/reflection/php_reflection.c)
--FILE--
<?php
declare(strict_types=1);

class UserClass {
    public function f(): void {}
}

$internal = new ReflectionMethod('DateTime', 'format');
echo $internal->isInternal() ? "internal_yes\n" : "internal_no\n";

$user = new ReflectionMethod('UserClass', 'f');
echo $user->isInternal() ? "user_internal_yes\n" : "user_internal_no\n";

$variadic = new ReflectionMethod('ReflectionMethod', 'invoke');
echo $variadic->isVariadic() ? "variadic_yes\n" : "variadic_no\n";

echo $internal->isVariadic() ? "format_variadic_yes\n" : "format_variadic_no\n";
--EXPECT--
internal_yes
user_internal_no
variadic_yes
format_variadic_no
