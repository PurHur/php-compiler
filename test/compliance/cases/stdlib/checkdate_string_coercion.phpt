--TEST--
stdlib checkdate() — numeric string month/day/year coercion (#12281, ext/standard/datetime.c)
--FILE--
<?php
if (!checkdate('2', '29', '2020')) {
    echo "bad\n";
    exit(1);
}
if (!checkdate(2, 29, 2020)) {
    echo "bad\n";
    exit(1);
}
if (checkdate('2', '30', '2020')) {
    echo "bad\n";
    exit(1);
}
try {
    checkdate('feb', 29, 2020);
    echo "bad\n";
    exit(1);
} catch (TypeError $e) {
    echo str_contains($e->getMessage(), 'must be of type int') ? "non-numeric-ok\n" : "bad\n";
}
echo "ok\n";
--EXPECT--
non-numeric-ok
ok
