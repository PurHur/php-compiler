--TEST--
Language: ReflectionAttribute::newInstance() Error on wrong Attribute::TARGET_* (#23528)
--FILE--
<?php
#[Attribute(Attribute::TARGET_CLASS)]
class OnlyClass {}
#[OnlyClass]
function attr_target_fn() {}
try {
    (new ReflectionFunction('attr_target_fn'))->getAttributes()[0]->newInstance();
    echo "fn:noerror\n";
} catch (Throwable $e) {
    echo 'fn:', get_class($e), ':', $e->getMessage(), "\n";
}

#[Attribute(Attribute::TARGET_METHOD)]
class OnlyMethod {}
#[OnlyMethod]
class C {}
try {
    (new ReflectionClass('C'))->getAttributes()[0]->newInstance();
    echo "class:noerror\n";
} catch (Throwable $e) {
    echo 'class:', get_class($e), ':', $e->getMessage(), "\n";
}

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class ClassOrMethod {}
#[ClassOrMethod]
function attr_cm_fn() {}
try {
    (new ReflectionFunction('attr_cm_fn'))->getAttributes()[0]->newInstance();
    echo "cm:noerror\n";
} catch (Throwable $e) {
    echo 'cm:', get_class($e), ':', $e->getMessage(), "\n";
}

#[Attribute(Attribute::TARGET_CLASS)]
class OkClass {}
#[OkClass]
class D {}
try {
    $o = (new ReflectionClass('D'))->getAttributes()[0]->newInstance();
    echo 'ok:', get_class($o), "\n";
} catch (Throwable $e) {
    echo 'ok:', get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
fn:Error:Attribute "OnlyClass" cannot target function (allowed targets: class)
class:Error:Attribute "OnlyMethod" cannot target class (allowed targets: method)
cm:Error:Attribute "ClassOrMethod" cannot target function (allowed targets: class, method)
ok:OkClass
