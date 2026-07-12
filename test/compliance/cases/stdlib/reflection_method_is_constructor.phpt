--TEST--
ReflectionMethod::isConstructor()/isDestructor()/isAbstract() (#18225, ext/reflection/php_reflection.c)
--FILE--
<?php
declare(strict_types=1);

class UserClass {
    public function f(): void {}
}

interface I {
    public function m();
}

class D {
    public function __destruct() {}
}

$ctor = new ReflectionMethod('DateTime', '__construct');
echo $ctor->isConstructor() ? "ctor_yes\n" : "ctor_no\n";

$dtor = new ReflectionMethod('D', '__destruct');
echo $dtor->isDestructor() ? "dtor_yes\n" : "dtor_no\n";

$abstract = new ReflectionMethod('I', 'm');
echo $abstract->isAbstract() ? "abstract_yes\n" : "abstract_no\n";

$user = new ReflectionMethod('UserClass', 'f');
echo $user->isConstructor() ? "user_ctor_yes\n" : "user_ctor_no\n";
--EXPECT--
ctor_yes
dtor_yes
abstract_yes
user_ctor_no
