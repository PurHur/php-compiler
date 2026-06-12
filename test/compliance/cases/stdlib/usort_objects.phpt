--TEST--
stdlib usort() closure on object-element arrays in place (#6501, ext/standard/array.c)
--FILE--
<?php
class C
{
    public int $i;

    public function __construct(int $i)
    {
        $this->i = $i;
    }
}
$a = [new C(2), new C(1)];
$ids = [spl_object_id($a[0]), spl_object_id($a[1])];
usort($a, fn ($x, $y) => $x->i <=> $y->i);
echo $a[0]->i, "\n";
echo spl_object_id($a[0]) === $ids[1] ? '1' : '0', "\n";
echo spl_object_id($a[1]) === $ids[0] ? '1' : '0', "\n";
--EXPECT--
1
1
1
