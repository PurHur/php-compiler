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
echo method_exists($ctor, 'isConstructor') ? "ctor_method_yes\n" : "ctor_method_no\n";
echo $ctor->isConstructor() ? "ctor_yes\n" : "ctor_no\n";

$dtor = new ReflectionMethod('D', '__destruct');
echo method_exists($dtor, 'isDestructor') ? "dtor_method_yes\n" : "dtor_method_no\n";
echo $dtor->isDestructor() ? "dtor_yes\n" : "dtor_no\n";

$abstract = new ReflectionMethod('I', 'm');
echo method_exists($abstract, 'isAbstract') ? "abstract_method_yes\n" : "abstract_method_no\n";
echo $abstract->isAbstract() ? "abstract_yes\n" : "abstract_no\n";

$user = new ReflectionMethod('UserClass', 'f');
echo $user->isConstructor() ? "user_ctor_yes\n" : "user_ctor_no\n";

echo "ok\n";
