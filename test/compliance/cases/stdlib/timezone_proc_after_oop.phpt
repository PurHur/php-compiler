--TEST--
stdlib timezone_identifiers_list() after DateTimeZone::listIdentifiers() — procedural twin stays array (#16802, ext/date/php_date.c)
--FILE--
<?php
$us = DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, 'US');
$n = count($us);
$proc = timezone_identifiers_list();
if (!is_array($proc)) {
    echo 'proc_type=', gettype($proc), "\n";
    exit(1);
}
echo count($proc) === count(DateTimeZone::listIdentifiers()) ? "proc_sync\n" : "proc_mismatch\n";
--EXPECT--
proc_sync
