--TEST--
AOT: namespace group use (issue #2443)
--FILE--
<?php
namespace N {
    class A {
        public static function id(): string { return 'A'; }
    }
    class B {
        public static function id(): string { return 'B'; }
    }
    const C1 = 1;
    const C2 = 2;
    function f(): string { return 'fn'; }
}
namespace User {
    use N\{A, B};
    use const N\{C1, C2};
    use function N\f;
    echo A::id(), B::id(), C1, C2, f(), "\n";
}
--EXPECT--
AB12fn
