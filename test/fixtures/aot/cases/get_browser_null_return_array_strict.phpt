--TEST--
AOT get_browser non-null UA, null $return_array under strict_types TypeError (#31289)
--FILE--
<?php
declare(strict_types=1);
try {
    get_browser('Mozilla/5.0', null);
    echo "fail\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
get_browser(): Argument #2 ($return_array) must be of type bool, null given
