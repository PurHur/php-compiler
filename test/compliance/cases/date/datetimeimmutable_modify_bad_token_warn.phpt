--TEST--
DateTimeImmutable::modify bad token → Unexpected character Warning (#31597)
--FILE--
<?php
error_reporting(E_ALL);
$warn = null;
set_error_handler(static function (int $n, string $m) use (&$warn): bool {
    $warn = $m;

    return true;
});

$d = new DateTimeImmutable('2020-01-01');
$r = $d->modify('@@@');
echo 'return=', var_export($r, true), "\n";
echo 'warning=', $warn === null ? 'NULL' : $warn, "\n";

$warn = null;
$r2 = $d->modify('foo');
echo 'alpha_return=', var_export($r2, true), "\n";
echo 'alpha_warning=', $warn === null ? 'NULL' : $warn, "\n";
?>
--EXPECT--
return=false
warning=DateTimeImmutable::modify(): Failed to parse time string (@@@) at position 0 (@): Unexpected character
alpha_return=false
alpha_warning=DateTimeImmutable::modify(): Failed to parse time string (foo) at position 0 (f): The timezone could not be found in the database
