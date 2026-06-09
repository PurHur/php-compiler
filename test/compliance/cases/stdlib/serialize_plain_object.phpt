--TEST--
stdlib serialize() plain objects and stdClass (#3621, ext/standard/var.c)
--FILE--
<?php
echo serialize(new stdClass()), "\n";
$o = new stdClass();
$o->x = 1;
$o->msg = 'hi';
echo serialize($o), "\n";
class C {
    public int $x = 1;
    public string $y = 'z';
}
$c = new C();
echo serialize($c), "\n";
$round = unserialize(serialize($c));
var_export($round instanceof C);
echo "\n";
var_export($round->x);
echo "\n";
var_export($round->y);
echo "\n";
$stdRound = unserialize(serialize($o));
var_export($stdRound instanceof stdClass);
echo "\n";
var_export($stdRound->x);
echo "\n";
var_export($stdRound->msg);
echo "\n";
--EXPECT--
O:8:"stdClass":0:{}
O:8:"stdClass":2:{s:1:"x";i:1;s:3:"msg";s:2:"hi";}
O:1:"C":2:{s:1:"x";i:1;s:1:"y";s:1:"z";}
true
1
'z'
true
1
'hi'
