--TEST--
duplicate trait class constants fatal on class declaration (issue #3431, #5385 zend_traits.c)
--FILE--
<?php
trait T1 {
    public const N = 1;
}
trait T2 {
    public const N = 2;
}
try {
    class C {
        use T1, T2;
    }
    echo "unreachable\n";
} catch (LogicException $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
T1 and T2 define the same constant (N) in the composition of C. However, the definition differs and is considered incompatible. Class was composed
