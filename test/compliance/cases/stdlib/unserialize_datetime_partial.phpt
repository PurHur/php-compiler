--TEST--
DateTime unserialize rejects partial Zend wire missing timezone fields (#10829)
--FILE--
<?php
declare(strict_types=1);
$blob = 'O:8:"DateTime":1:{s:4:"date";s:19:"2020-01-01 00:00:00";}';
try {
    unserialize($blob);
    echo "uncaught\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
$zend = 'O:8:"DateTime":3:{s:4:"date";s:26:"2020-01-01 00:00:00.000000";s:13:"timezone_type";i:3;s:8:"timezone";s:3:"UTC";}';
$dt = unserialize($zend);
echo $dt->format('Y-m-d'), "\n";
--EXPECT--
Invalid serialization data for DateTime object
2020-01-01
