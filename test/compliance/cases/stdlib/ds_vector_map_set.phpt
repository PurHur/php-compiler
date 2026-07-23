--TEST--
Ds\Vector / Ds\Map / Ds\Set MVP (#22549, php-ds/ext-ds)
--SKIPIF--
<?php
if (!extension_loaded('ds') || !class_exists('Ds\\Vector')) {
    die('skip ds');
}
?>
--FILE--
<?php
declare(strict_types=1);

echo extension_loaded('ds') ? '1' : '0', "\n";
echo class_exists('Ds\\Vector') ? '1' : '0', "\n";
echo class_exists('Ds\\Map') ? '1' : '0', "\n";
echo class_exists('Ds\\Set') ? '1' : '0', "\n";

$v = new Ds\Vector([1, 2, 3]);
echo $v->count(), "\n";

$m = new Ds\Map(['a' => 1]);
echo $m->get('a'), "\n";
echo $m->count(), "\n";

$s = new Ds\Set([1, 2, 2, 3]);
echo $s->count(), "\n";
echo $s->contains(2) ? '1' : '0', "\n";
$s->add(4);
echo $s->count(), "\n";
--EXPECT--
1
1
1
1
3
1
1
3
1
4
