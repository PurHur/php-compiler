--TEST--
AOT gethostname() local hostname (issue #3465)
--FILE--
<?php
$h = gethostname();
if (!is_string($h) || strlen($h) === 0) {
    echo "false\n";
} else {
    echo "host\n";
}
--EXPECT--
host
