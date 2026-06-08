--TEST--
Stdlib: explode/substr_count empty delimiter/needle throw ValueError (#4279, ext/standard/string.c)
--FILE--
<?php
try {
    explode('', 'a');
    echo "explode: ok\n";
} catch (ValueError $e) {
    echo "explode: ValueError\n";
} catch (Throwable $e) {
    echo 'explode: ', get_class($e), "\n";
}
try {
    substr_count('hay', '');
    echo "substr_count: ok\n";
} catch (ValueError $e) {
    echo "substr_count: ValueError\n";
} catch (Throwable $e) {
    echo 'substr_count: ', get_class($e), "\n";
}
echo strpos('hay', ''), "\n";
echo strrpos('hay', ''), "\n";
echo strstr('hay', '') === 'hay' ? '1' : '0', "\n";
echo strrchr('hay', '') === false ? '1' : '0', "\n";
--EXPECT--
explode: ValueError
substr_count: ValueError
0
3
1
1
