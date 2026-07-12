--TEST--
ReflectionMethod::getTentativeReturnType() on internal DateTime::format (#18226, ext/reflection/php_reflection.c)
--FILE--
<?php
declare(strict_types=1);

$rm = new ReflectionMethod('DateTime', 'format');
echo $rm->getTentativeReturnType()?->getName() ?? 'null';
echo "\n";
echo $rm->hasTentativeReturnType() ? "has_yes\n" : "has_no\n";

class Typed {
    public function typed(): int { return 1; }
}

$user = new ReflectionMethod(Typed::class, 'typed');
echo $user->hasTentativeReturnType() ? "user_has_yes\n" : "user_has_no\n";
echo null === $user->getTentativeReturnType() ? "user_get_null\n" : "user_get_set\n";
--EXPECT--
string
has_yes
user_has_no
user_get_null
