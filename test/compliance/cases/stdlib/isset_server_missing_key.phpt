--TEST--
stdlib isset() on $_SERVER missing key must not warn (issue #539)
--FILE--
<?php
if (isset($_SERVER['PATH_INFO'])) {
    echo "present\n";
} else {
    echo "absent\n";
}
--EXPECT--
absent
