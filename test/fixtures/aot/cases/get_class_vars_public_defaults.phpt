--TEST--
AOT get_class_vars public instance+static; omit private (#27229)
--FILE--
<?php
class C27229
{
    public $a = 1;
    private $b = 2;
    public static $c = 3;
}
$r = get_class_vars('C27229');
if (!is_array($r)) {
    echo 'NOTARRAY';
    echo PHP_EOL;
    return;
}
$keys = [];
$vals = [];
foreach ($r as $k => $v) {
    $keys[] = $k;
    $vals[] = $v;
}
echo implode(',', $keys), '|', implode(',', $vals), PHP_EOL;
--EXPECT--
a,c|1,3
