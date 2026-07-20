--TEST--
date/gmdate(null) soft-null; date_create null coerce on 8.4 forward profile JIT (#21208, reverts #19651; ext/date/php_date.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $msg): bool {
    if (E_DEPRECATED === $no) {
        echo "DEP\n";
        return true;
    }
    return false;
});
foreach (['date', 'gmdate'] as $fn) {
    try {
        $r = $fn(null);
        echo "$fn: OK ", var_export($r, true), "\n";
    } catch (TypeError $e) {
        echo "$fn: TypeError\n";
    }
}
$dt = date_create(null);
echo 'date_create(null)=' . (false === $dt ? 'false' : get_class($dt)) . "\n";
$dti = date_create_immutable(null);
echo 'date_create_immutable(null)=' . (false === $dti ? 'false' : get_class($dti)) . "\n";
--EXPECT--
DEP
date: OK ''
DEP
gmdate: OK ''
DEP
date_create(null)=DateTime
DEP
date_create_immutable(null)=DateTimeImmutable
