--TEST--
stdlib str_rot13() VM — TypeError for non-string operand (#4578)
--FILE--
<?php
try {
    str_rot13([]);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    str_rot13(new stdClass());
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
echo str_rot13('hello'), "\n";
--EXPECT--
TypeError: str_rot13(): Argument #1 ($string) must be of type string, array given
TypeError: str_rot13(): Argument #1 ($string) must be of type string, stdClass given
uryyb
