--TEST--
stdlib usort/uasort/uksort() null callback — TypeError (#10624, ext/standard/array.c)
--FILE--
<?php
foreach (['usort', 'uasort', 'uksort'] as $fn) {
    try {
        if ('usort' === $fn) {
            $arr = [1, 2];
            $fn($arr, null);
        } else {
            $arr = [1 => 2, 3 => 4];
            $fn($arr, null);
        }
    } catch (Throwable $e) {
        echo $fn, ': ', get_class($e), "\n";
    }
}
?>
--EXPECT--
usort: TypeError
uasort: TypeError
uksort: TypeError
