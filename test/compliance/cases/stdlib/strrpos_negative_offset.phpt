--TEST--
stdlib strrpos() negative offset suffix window (issue #4104, ext/standard/string.c)
--FILE--
<?php
declare(strict_types=1);
echo strrpos('abcabc', 'bc', -3), "\n";
echo strrpos('abcabc', 'bc', -1), "\n";
echo strrpos('abcabc', 'bc', -6) == false ? "false\n" : "?\n";
try {
    $_ = strrpos('abcabc', 'bc', -7);
    echo "no error\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
--EXPECT--
1
4
false
ValueError
