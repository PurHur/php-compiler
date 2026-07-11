--TEST--
stdlib date/gmdate/getdate/idate reject float timestamp under strict_types (#14892, ext/date/php_date.c)
--FILE--
<?php
declare(strict_types=1);

try {
    gmdate('u', 1.23456789);
    echo "gmdate: no error\n";
} catch (TypeError $e) {
    echo "gmdate: TypeError\n";
}

try {
    getdate(1.5);
    echo "getdate: no error\n";
} catch (TypeError $e) {
    echo "getdate: TypeError\n";
}

try {
    date('Y', 1.5);
    echo "date: no error\n";
} catch (TypeError $e) {
    echo "date: TypeError\n";
}

try {
    idate('s', 1.5);
    echo "idate: no error\n";
} catch (TypeError $e) {
    echo "idate: TypeError\n";
}
--EXPECT--
gmdate: TypeError
getdate: TypeError
date: TypeError
idate: TypeError
