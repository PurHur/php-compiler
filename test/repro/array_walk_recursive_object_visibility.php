<?php
// #23565 — array_walk_recursive object visibility + mangled keys
class O
{
    public $a = [10, 20];
    protected $b = 30;
    private $c = 40;
}
$o = new O();
$keys = [];
array_walk_recursive($o, function ($v, $k) use (&$keys) {
    $keys[] = [$k, $v];
});
foreach ($keys as $pair) {
    echo bin2hex((string) $pair[0]), '=', $pair[1], "\n";
}
