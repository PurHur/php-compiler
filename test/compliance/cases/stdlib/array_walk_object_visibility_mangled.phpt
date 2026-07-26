--TEST--
stdlib array_walk() walks protected/private props with Zend-mangled keys (#23552, #23431)
--FILE--
<?php
class A
{
    public $a = 1;
    protected $b = 2;
    private $c = 3;
}
$seen = [];
array_walk(new A(), function ($v, $k) use (&$seen) {
    $seen[] = is_string($k) && str_contains($k, "\0") ? bin2hex($k) : (string) $k;
});
sort($seen);
echo implode(",", $seen), "\n";
echo "n=", count($seen), "\n";
?>
--EXPECT--
002a0062,00410063,a
n=3
