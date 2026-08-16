--TEST--
stdlib trim/ltrim/rtrim/chop(null $characters) soft DEP+coerce outside strict_types (#31386, ext/standard/string.c)
--FILE--
<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
foreach (['trim', 'ltrim', 'rtrim', 'chop'] as $fn) {
    try {
        $r = $fn(' x ', null);
        echo $fn, ':', var_export($r, true), "\n";
    } catch (Throwable $e) {
        echo $fn, ':', get_class($e), ':', $e->getMessage(), "\n";
    }
}
?>
--EXPECTF--
%ADeprecated: trim(): Passing null to parameter #2 ($characters) of type string is deprecated in %s on line %d
trim:' x '
%ADeprecated: ltrim(): Passing null to parameter #2 ($characters) of type string is deprecated in %s on line %d
ltrim:' x '
%ADeprecated: rtrim(): Passing null to parameter #2 ($characters) of type string is deprecated in %s on line %d
rtrim:' x '
%ADeprecated: chop(): Passing null to parameter #2 ($characters) of type string is deprecated in %s on line %d
chop:' x '
