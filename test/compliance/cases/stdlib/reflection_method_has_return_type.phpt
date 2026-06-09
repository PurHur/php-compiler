--TEST--
stdlib ReflectionMethod::hasReturnType() / ReflectionFunction::hasReturnType() (#5141, ext/reflection/php_reflection.c)
--FILE--
<?php
class A {
    static function typed(): void {}
    function untyped() {}
    function typedInstance(): int { return 1; }
}

function untypedFn() {}
function typedFn(): string { return ''; }
function explicitMixed(): mixed { return 1; }

echo (new ReflectionMethod(A::class, 'typed'))->hasReturnType() ? '1' : '0', "\n";
echo (new ReflectionMethod(A::class, 'untyped'))->hasReturnType() ? '1' : '0', "\n";
echo (new ReflectionMethod(A::class, 'typedInstance'))->hasReturnType() ? '1' : '0', "\n";
echo (new ReflectionFunction('typedFn'))->hasReturnType() ? '1' : '0', "\n";
echo (new ReflectionFunction('untypedFn'))->hasReturnType() ? '1' : '0', "\n";
echo (new ReflectionFunction('explicitMixed'))->hasReturnType() ? '1' : '0', "\n";
--EXPECT--
1
0
1
1
0
1
