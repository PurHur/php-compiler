--TEST--
date/gmdate/date_create null coerce on 8.4 forward profile (#18902 #18903, ext/date/php_date.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo 'date(null)=[' . date(null) . "]\n";
echo 'gmdate(null)=[' . gmdate(null) . "]\n";
$dt = date_create(null);
echo 'date_create(null)=' . (false === $dt ? 'false' : get_class($dt)) . "\n";
$dti = date_create_immutable(null);
echo 'date_create_immutable(null)=' . (false === $dti ? 'false' : get_class($dti)) . "\n";
--EXPECT--
date(null)=[]
gmdate(null)=[]
date_create(null)=DateTime
date_create_immutable(null)=DateTimeImmutable
