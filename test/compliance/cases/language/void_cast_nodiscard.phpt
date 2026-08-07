--TEST--
Language: (void) cast assignment still ParseError on PROFILE=8.5 (#28441, statement-only)
--ENV--
PHP_COMPILER_PROFILE=8.5
--FILE--
<?php
try {
    eval('$z = (void)1;');
    echo "void_ok\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
--EXPECT--
ParseError
