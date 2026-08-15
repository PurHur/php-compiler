--TEST--
iconv() null $from_encoding / $to_encoding soft DEP+coerce on default profile (#31309, ext/iconv/iconv.c)
--FILE--
<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
try {
    echo var_export(iconv(null, 'UTF-8', 'a'), true), "\n";
    echo var_export(iconv('UTF-8', null, 'a'), true), "\n";
    echo var_export(iconv('UTF-8', 'UTF-8', 'a'), true), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
?>
--EXPECTF--
%ADeprecated: iconv(): Passing null to parameter #1 ($from_encoding) of type string is deprecated in %s on line %d
'a'
%ADeprecated: iconv(): Passing null to parameter #2 ($to_encoding) of type string is deprecated in %s on line %d
'a'
'a'
