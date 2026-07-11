--TEST--
stdlib php_user_filter — class exists, PSFS constants, stream_filter_register (#11747)
--FILE--
<?php
declare(strict_types=1);
echo class_exists('php_user_filter') ? '1' : '0';
echo "\n";
echo PSFS_PASS_ON, "\n";
class T extends php_user_filter {
    public function filter($in, $out, &$consumed, $closing): int {
        return PSFS_PASS_ON;
    }
}
var_export(stream_filter_register('t.test', T::class));
?>
--EXPECT--
1
2
true
