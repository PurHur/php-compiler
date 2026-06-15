--TEST--
stdlib str_contains/trim/ucwords/chop — enum case TypeError regression guard JIT (#8691)
--JIT--
--FILE--
<?php
enum Es: string { case X = 'hi'; }

foreach (['str_contains', 'str_starts_with', 'str_ends_with', 'trim', 'chop', 'ucwords'] as $fn) {
    try {
        if ('str_contains' === $fn) {
            str_contains(Es::X, 'h');
        } elseif ('str_starts_with' === $fn) {
            str_starts_with(Es::X, 'h');
        } elseif ('str_ends_with' === $fn) {
            str_ends_with(Es::X, 'i');
        } elseif ('trim' === $fn) {
            trim(Es::X);
        } elseif ('chop' === $fn) {
            chop(Es::X);
        } else {
            ucwords(Es::X);
        }
        echo $fn, ': uncaught', "\n";
    } catch (TypeError $e) {
        echo $fn, ': TypeError', "\n";
    }
}
?>
--EXPECT--
str_contains: TypeError
str_starts_with: TypeError
str_ends_with: TypeError
trim: TypeError
chop: TypeError
ucwords: TypeError
