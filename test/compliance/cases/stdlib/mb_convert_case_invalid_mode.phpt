--TEST--
stdlib mb_convert_case() — invalid mode ValueError (#7014)
--FILE--
<?php
try {
    mb_convert_case('hello', 99, 'UTF-8');
    echo "uncaught\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
mb_convert_case(): Argument #2 ($mode) must be one of the MB_CASE_* constants
