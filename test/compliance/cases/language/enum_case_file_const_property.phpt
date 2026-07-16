--TEST--
Language: file-scope const with EnumCase->value / ->name (#19567, zend_compile.c)
--FILE--
<?php
namespace N {
    enum E: string {
        case A = 'ns';
    }
    const XS = E::A->value;
    echo XS, "\n";
}

namespace {
    enum E: int {
        case A = 1;
    }
    const XV = E::A->value;
    echo XV, "\n";

    enum U {
        case A;
    }
    const XN = U::A->name;
    echo XN, "\n";

    const XF = N\E::A->value;
    echo XF, "\n";

    class C {
        public const X = E::A->value;
    }
    echo C::X, "\n";

    function f(int $n = E::A->value): int {
        return $n;
    }
    echo f(), "\n";
}
--EXPECT--
ns
1
A
ns
1
1
