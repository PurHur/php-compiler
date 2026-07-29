--TEST--
stdlib pathinfo() flags 0 / null → empty string (#24941)
--FILE--
<?php
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $str): bool {
    if ($no === E_DEPRECATED) {
        echo "DEP\n";
        return true;
    }
    return false;
});

$z = pathinfo('/a/b.txt', 0);
echo 'flags0=', var_export($z, true), ' type=', gettype($z), "\n";

$n = pathinfo('/a/b.txt', null);
echo 'flags_null=', var_export($n, true), ' type=', gettype($n), "\n";
--EXPECT--
flags0='' type=string
DEP
flags_null='' type=string
