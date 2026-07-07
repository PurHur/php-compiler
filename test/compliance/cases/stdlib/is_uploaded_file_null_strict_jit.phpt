--TEST--
stdlib is_uploaded_file() JIT — null filename TypeError under strict_types (#17061, ext/standard/filestat.c)
--FILE--
<?php
declare(strict_types=1);

try {
    is_uploaded_file(null);
    echo "fail\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
is_uploaded_file(): Argument #1 ($filename) must be of type string, null given
