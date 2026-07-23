--TEST--
Language: ternary as first call arg with trailing literal binds independently (#22732, Zend/zend_compile.c)
--FILE--
<?php
function two($a, $b): void
{
    echo 'a=';
    var_dump($a);
    echo 'b=';
    var_dump($b);
}
function three($a, $b, $c): void
{
    echo 'a=';
    var_dump($a);
    echo 'b=';
    var_dump($b);
    echo 'c=';
    var_dump($c);
}
function val(): int
{
    return 7;
}
two(1 ? val() : 0, true);
two(true, 1 ? 7 : 0);
three(true, 1 ? 7 : 0, false);
echo 've=', var_export(1 ? 7 : 0, true), "\n";
?>
--EXPECT--
a=int(7)
b=bool(true)
a=bool(true)
b=int(7)
a=bool(true)
b=int(7)
c=bool(false)
ve=7
