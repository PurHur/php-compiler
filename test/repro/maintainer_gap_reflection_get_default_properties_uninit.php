<?php
class G {
    public $a = 1;
    public $b;
    public int $c = 2;
    public $d = null;
    public int $e;
}
$rc = new ReflectionClass(G::class);
$d = $rc->getDefaultProperties();
ksort($d);
echo "defaults:";
foreach ($d as $k => $v) {
    echo " $k=";
    var_export($v);
}
echo "\n";
foreach (['a','b','c','d','e'] as $name) {
    $p = $rc->getProperty($name);
    echo "$name hasDefault=" . ($p->hasDefaultValue() ? '1' : '0')
        . " default=";
    if ($p->hasDefaultValue()) {
        var_export($p->getDefaultValue());
    } else {
        echo 'N/A';
    }
    echo "\n";
}
