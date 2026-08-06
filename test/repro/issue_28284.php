<?php
/**
 * #28284 — array_search() too many / too few args → ArgumentCountError (Zend), not LogicException.
 */
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
