--TEST--
stdlib strip_tags Reflection allowed_tags array|string|null (#25594)
--FILE--
<?php
$r = new ReflectionFunction('strip_tags');
foreach ($r->getParameters() as $p) {
    $t = $p->getType();
    echo $p->getName(), '|', null === $t ? '-' : (string) $t;
    if ($p->isOptional() && $p->isDefaultValueAvailable()) {
        echo '|def=', var_export($p->getDefaultValue(), true);
    } elseif ($p->isOptional()) {
        echo '|def=?';
    }
    echo "\n";
}
echo strip_tags('<b>x</b><i>y</i>', ['b']), "\n";
echo strip_tags('<b>x</b><i>y</i>', '<b>'), "\n";
echo strip_tags('<b>x</b><i>y</i>'), "\n";
?>
--EXPECT--
string|string
allowed_tags|array|string|null|def=NULL
<b>x</b>y
<b>x</b>y
xy
