--TEST--
Language: object relational < > <= >= via zend_compare_objects (#25241, re-#3445/#3691)
--FILE--
<?php
class Point {
    public function __construct(public int $x) {}
}
class EmptyA {}

$a = new Point(1);
$b = new Point(2);
$same = new Point(1);

var_export($a < $b); echo "\n";
var_export($b > $a); echo "\n";
var_export($a < $same); echo "\n";
var_export($a <= $same); echo "\n";
var_export($a >= $same); echo "\n";
var_export($a > $same); echo "\n";

var_export((new EmptyA) < (new EmptyA)); echo "\n";
var_export((new EmptyA) <= (new EmptyA)); echo "\n";
var_export((new EmptyA) > (new EmptyA)); echo "\n";
var_export((new EmptyA) >= (new EmptyA)); echo "\n";

var_export((object)['x' => 1] < (object)['x' => 2]); echo "\n";
echo ($a <=> $b), "\n";
echo ((new EmptyA) <=> (new EmptyA)), "\n";
?>
--EXPECT--
true
true
false
true
true
false
false
true
false
true
true
-1
0
