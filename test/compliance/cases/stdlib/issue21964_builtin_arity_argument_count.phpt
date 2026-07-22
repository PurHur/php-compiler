--TEST--
stdlib wrong argc — ArgumentCountError not LogicException (#21964, Zend zend_API.c)
--FILE--
<?php
$cases = [
    'strpos' => static function () { strpos(); },
    'implode' => static function () { implode(); },
    'preg_match' => static function () { preg_match(); },
    'json_encode' => static function () { json_encode(); },
    'count' => static function () { count(); },
    'abs' => static function () { abs(); },
    'defined' => static function () { defined(); },
    'constant' => static function () { constant(); },
    'gettype' => static function () { gettype(); },
    'settype' => static function () { $x = 1; settype($x); },
    'uniqid' => static function () { uniqid('a', false, 'x'); },
];
foreach ($cases as $name => $fn) {
    try {
        $fn();
        echo $name, " ran\n";
    } catch (Throwable $e) {
        echo $name, ' ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
strpos ArgumentCountError: strpos() expects at least 2 arguments, 0 given
implode ArgumentCountError: implode() expects at least 1 argument, 0 given
preg_match ArgumentCountError: preg_match() expects at least 2 arguments, 0 given
json_encode ArgumentCountError: json_encode() expects at least 1 argument, 0 given
count ArgumentCountError: count() expects at least 1 argument, 0 given
abs ArgumentCountError: abs() expects exactly 1 argument, 0 given
defined ArgumentCountError: defined() expects exactly 1 argument, 0 given
constant ArgumentCountError: constant() expects exactly 1 argument, 0 given
gettype ArgumentCountError: gettype() expects exactly 1 argument, 0 given
settype ArgumentCountError: settype() expects exactly 2 arguments, 1 given
uniqid ArgumentCountError: uniqid() expects at most 2 arguments, 3 given
