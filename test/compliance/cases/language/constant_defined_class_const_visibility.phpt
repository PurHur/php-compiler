--TEST--
constant()/defined() honor private/protected class const visibility (issue #29130, Zend zend_builtin_functions.c)
--FILE--
<?php
class A {
    private const X = 1;
    protected const Y = 2;
    public const Z = 3;
    public static function probe(): void {
        foreach (['A::X', 'A::Y', 'A::Z'] as $n) {
            try {
                $v = constant($n);
                echo "in $n=", $v, "\n";
            } catch (Throwable $e) {
                echo "in $n=", $e->getMessage(), "\n";
            }
        }
        echo 'in defX=', defined('A::X') ? '1' : '0', "\n";
        echo 'in defY=', defined('A::Y') ? '1' : '0', "\n";
        echo 'in defZ=', defined('A::Z') ? '1' : '0', "\n";
    }
}
class B extends A {
    public static function probe(): void {
        foreach (['A::X', 'A::Y', 'A::Z'] as $n) {
            try {
                $v = constant($n);
                echo "sub $n=", $v, "\n";
            } catch (Throwable $e) {
                echo "sub $n=", $e->getMessage(), "\n";
            }
        }
        echo 'sub defX=', defined('A::X') ? '1' : '0', "\n";
        echo 'sub defY=', defined('A::Y') ? '1' : '0', "\n";
        echo 'sub defZ=', defined('A::Z') ? '1' : '0', "\n";
    }
}
foreach (['A::X', 'A::Y', 'A::Z'] as $n) {
    try {
        $v = constant($n);
        echo "out $n=", $v, "\n";
    } catch (Throwable $e) {
        echo "out $n=", $e->getMessage(), "\n";
    }
}
echo 'out defX=', defined('A::X') ? '1' : '0', "\n";
echo 'out defY=', defined('A::Y') ? '1' : '0', "\n";
echo 'out defZ=', defined('A::Z') ? '1' : '0', "\n";
A::probe();
B::probe();
--EXPECT--
out A::X=Cannot access private constant A::X
out A::Y=Cannot access protected constant A::Y
out A::Z=3
out defX=0
out defY=0
out defZ=1
in A::X=1
in A::Y=2
in A::Z=3
in defX=1
in defY=1
in defZ=1
sub A::X=Cannot access private constant A::X
sub A::Y=2
sub A::Z=3
sub defX=0
sub defY=1
sub defZ=1
