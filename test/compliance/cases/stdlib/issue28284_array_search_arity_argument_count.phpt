--TEST--
stdlib array_search() wrong argc — ArgumentCountError not LogicException (#28284, Zend array.stub.php)
--FILE--
<?php
$cases = [
    'too_few_0' => static function () { array_search(); },
    'too_few_1' => static function () { array_search(1); },
    'too_many_4' => static function () { array_search(1, [1], true, true); },
    'ok_2' => static function () { return array_search(1, [1]); },
    'ok_3' => static function () { return array_search(1, [1], true); },
];
foreach ($cases as $name => $fn) {
    try {
        $r = $fn();
        echo $name, ' ok=', var_export($r, true), "\n";
    } catch (Throwable $e) {
        echo $name, ' ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
too_few_0 ArgumentCountError: array_search() expects at least 2 arguments, 0 given
too_few_1 ArgumentCountError: array_search() expects at least 2 arguments, 1 given
too_many_4 ArgumentCountError: array_search() expects at most 3 arguments, 4 given
ok_2 ok=0
ok_3 ok=0
