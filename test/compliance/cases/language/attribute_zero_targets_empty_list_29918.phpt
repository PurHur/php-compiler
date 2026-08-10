--TEST--
Language: Attribute(0) newInstance Error has empty allowed-targets list (#29918)
--FILE--
<?php
#[Attribute(0)]
class A {}
#[A]
class C {}
try {
    (new ReflectionClass(C::class))->getAttributes()[0]->newInstance();
    echo "noerror\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
Error:Attribute "A" cannot target class (allowed targets: )
