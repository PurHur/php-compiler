--TEST--
language: weak typed int rejects INF/NAN with TypeError (#27925, zend_dval_to_lval_safe)
--FILE--
<?php
function f(int $i): int
{
    return $i;
}
class C
{
    public int $x;
}
foreach ([INF, -INF, NAN] as $v) {
    try {
        f($v);
        echo "param:ok\n";
    } catch (TypeError $e) {
        echo "param:TypeError\n";
    }
}
function r(): int
{
    return INF;
}
try {
    r();
    echo "return:ok\n";
} catch (TypeError $e) {
    echo "return:TypeError\n";
}
$c = new C();
try {
    $c->x = INF;
    echo "prop:ok\n";
} catch (TypeError $e) {
    echo "prop:TypeError\n";
}
try {
    chr(INF);
    echo "chr:ok\n";
} catch (TypeError $e) {
    echo "chr:TypeError\n";
}
// Cast path must stay silent truncate (not typed coerce).
var_export((int) INF);
echo "\n";
var_export(intval(NAN));
echo "\n";
--EXPECT--
param:TypeError
param:TypeError
param:TypeError
return:TypeError
prop:TypeError
chr:TypeError
0
0
