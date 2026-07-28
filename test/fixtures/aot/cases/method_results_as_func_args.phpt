--TEST--
AOT: free function receives two method-call results (#23971 c07_method)
--FILE--
<?php
class M
{
    public function g($v)
    {
        return "m$v";
    }
}
function f($a, $b)
{
    echo "$a $b\n";
}
$m = new M;
f($m->g(1), $m->g(2));
--EXPECT--
m1 m2
