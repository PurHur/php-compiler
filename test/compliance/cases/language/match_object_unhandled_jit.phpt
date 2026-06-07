--TEST--
JIT: match on object subject throws UnhandledMatchError with type name (issue #7404)
--FILE--
<?php
class C {}
class D {}
try {
    match (new C()) {
        new D() => 'd',
    };
    echo "no throw\n";
} catch (UnhandledMatchError $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
UnhandledMatchError: Unhandled match case of type C
