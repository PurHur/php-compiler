--TEST--
Language: (array) object cast — public properties (#3328)
--FILE--
<?php
class C {
    public int $x = 1;
    public string $y = 'hi';
}
$a = (array) new C();
echo count($a);
echo $a['x'];
echo $a['y'];
$b = [1 => 'one', 'k' => 'two'];
$c = (array) $b;
echo count($c);
echo $c[1];
echo $c['k'];
$c[1] = 'changed';
echo $b[1];
echo "\n";
--EXPECT--
21hi2onetwoone
