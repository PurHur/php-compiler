--TEST--
date/gmdate null TypeError; date_create null coerce on 8.4 forward profile (#19651 #18903, ext/date/php_date.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach (['date', 'gmdate'] as $fn) {
    try {
        $fn(null);
        echo "$fn: uncaught\n";
    } catch (TypeError $e) {
        echo "$fn: TypeError\n";
    }
}
$dt = date_create(null);
echo 'date_create(null)=' . (false === $dt ? 'false' : get_class($dt)) . "\n";
$dti = date_create_immutable(null);
echo 'date_create_immutable(null)=' . (false === $dti ? 'false' : get_class($dti)) . "\n";
--EXPECT--
date: TypeError
gmdate: TypeError
date_create(null)=DateTime
date_create_immutable(null)=DateTimeImmutable
