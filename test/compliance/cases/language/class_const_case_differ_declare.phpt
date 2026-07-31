--TEST--
Language: class/enum constants differing only by case are distinct (Zend/zend_compile.c, #25929)
--FILE--
<?php
class ClassConstCaseDiffer
{
    public const A = 1;
    public const a = 2;
}
echo ClassConstCaseDiffer::A, ' ', ClassConstCaseDiffer::a, "\n";

enum EnumCaseDiffer
{
    case A;
    case a;
}
foreach (EnumCaseDiffer::cases() as $c) {
    echo $c->name, ' ';
}
echo "\n";
echo EnumCaseDiffer::A === EnumCaseDiffer::a ? "same\n" : "diff\n";

try {
    echo ClassConstCaseDiffer::X;
} catch (Error $e) {
    // keep #25910: wrong-case fetch still undefined when only A/a exist
}
echo ClassConstCaseDiffer::A !== ClassConstCaseDiffer::a ? "ok\n" : "bad\n";
--EXPECT--
1 2
A a 
diff
ok
