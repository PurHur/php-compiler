--TEST--
ReflectionClassConstant getDocComment / __toString (#22419)
--FILE--
<?php
class T {
    /** doc for X */
    public const X = 1;
    protected const Y = 'hi';
    private const Z = 3.5;
    final public const F = 9;
}

$r = new ReflectionClassConstant(T::class, 'X');
var_export($r->getDocComment());
echo "\n";
echo (string) $r;
foreach (['Y', 'Z', 'F'] as $n) {
    echo (string) (new ReflectionClassConstant(T::class, $n));
}
var_export((new ReflectionClassConstant(T::class, 'Y'))->getDocComment());
echo "\n";
?>
--EXPECT--
'/** doc for X */'
Constant [ public int X ] { 1 }
Constant [ protected string Y ] { hi }
Constant [ private float Z ] { 3.5 }
Constant [ final public int F ] { 9 }
false
