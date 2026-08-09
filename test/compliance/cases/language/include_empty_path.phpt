--TEST--
Language: include/require empty path throws ValueError (#11032, Zend/zend_stream.c)
--FILE--
<?php
declare(strict_types=1);

try {
    include '';
    echo "include_bad\n";
} catch (ValueError $e) {
    echo 'include: ', $e->getMessage(), "\n";
}

try {
    require '';
    echo "require_bad\n";
} catch (ValueError $e) {
    echo 'require: ', $e->getMessage(), "\n";
}
--EXPECT--
include: Path must not be empty
require: Path must not be empty
