--TEST--
stdlib get_browser(null, null) under strict_types TypeError on $return_array (#31289, ext/standard/browscap.c)
--FILE--
<?php
declare(strict_types=1);
try {
    get_browser(null, null);
    echo "fail\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
get_browser(): Argument #2 ($return_array) must be of type bool, null given
