--TEST--
stdlib array_walk_recursive() walks protected/private object props with mangled keys (#23565)
--FILE--
<?php
class O
{
    public $a = [10, 20];
    protected $b = 30;
    private $c = 40;
}
$o = new O();
$keys = [];
array_walk_recursive($o, function ($v, $k) use (&$keys) {
    $keys[] = bin2hex((string) $k);
});
echo implode("\n", $keys), "\n";
echo "count=", count($keys), "\n";
?>
--EXPECT--
30
31
002a0062
004f0063
count=4
