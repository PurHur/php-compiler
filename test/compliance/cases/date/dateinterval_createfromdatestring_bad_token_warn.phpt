--TEST--
DateInterval::createFromDateString bad token → Unexpected character Warning (#31575)
--FILE--
<?php
error_reporting(E_ALL);
$warn = null;
set_error_handler(static function (int $n, string $m) use (&$warn): bool {
    $warn = $m;

    return true;
});

$r = DateInterval::createFromDateString('@@@');
echo 'return=', var_export($r, true), "\n";
echo 'warning=', $warn === null ? 'NULL' : $warn, "\n";

$warn = null;
$r2 = DateInterval::createFromDateString('foo');
echo 'alpha_return=', var_export($r2, true), "\n";
echo 'alpha_warning=', $warn === null ? 'NULL' : $warn, "\n";
?>
--EXPECT--
return=false
warning=DateInterval::createFromDateString(): Unknown or bad format (@@@) at position 0 (@): Unexpected character
alpha_return=false
alpha_warning=DateInterval::createFromDateString(): Unknown or bad format (foo) at position 0 (f): The timezone could not be found in the database
