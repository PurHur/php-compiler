--TEST--
date DateInterval unset living props is Zend no-op (#26180, ext/date/php_date.c)
--FILE--
<?php
$i = new DateInterval('P2Y3M4DT5H6M7S');
$i->f = 0.25;
foreach (['y', 'm', 'd', 'h', 'i', 's', 'f', 'invert', 'days'] as $p) {
    $before = $i->$p;
    unset($i->$p);
    $after = $i->$p;
    echo $p, '=', $before === $after ? 'kept' : 'cleared', "\n";
}
$i->d = 9;
echo 'write=', $i->d, "\n";
$i->extra = 1;
unset($i->extra);
echo 'extra_isset=', isset($i->extra) ? '1' : '0', "\n";
?>
--EXPECT--
y=kept
m=kept
d=kept
h=kept
i=kept
s=kept
f=kept
invert=kept
days=kept
write=9
extra_isset=0
