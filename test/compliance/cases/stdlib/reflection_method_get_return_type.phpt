--TEST--
stdlib ReflectionMethod::getReturnType() / isDeprecated() / hasTentativeReturnType() (#6597, ext/reflection/php_reflection.c)
--FILE--
<?php
class Legacy {
    #[\Deprecated]
    public function run(): void {}
}

class Typed {
    public function typed(): int { return 1; }
    public function untyped() {}
}

$m = new ReflectionMethod(Typed::class, 'typed');
var_export($m->getReturnType()?->getName());
echo "\n";
var_export($m->hasReturnType());
echo "\n";
var_export($m->hasTentativeReturnType());
echo "\n";

$u = new ReflectionMethod(Typed::class, 'untyped');
var_export($u->getReturnType());
echo "\n";
var_export($u->hasReturnType());
echo "\n";
var_export($u->hasTentativeReturnType());
echo "\n";

$d = new ReflectionMethod(Legacy::class, 'run');
var_export($d->isDeprecated());
echo "\n";
var_export($d->getReturnType()?->getName());
echo "\n";
--EXPECT--
'int'
true
false
NULL
false
false
true
'void'
