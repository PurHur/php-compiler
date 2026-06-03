--TEST--
stdlib array_unique() rejects plain objects (#4698)
--FILE--
<?php
$a = array(new stdClass(), new stdClass());
try {
    array_unique($a);
    echo "ok\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
Error: Object of class stdClass could not be converted to string
