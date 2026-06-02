--TEST--
continue targeting switch emits E_WARNING (issue #4502, Zend zend_compile.c)
--FILE--
<?php
function warn_capture(int $errno, string $message): bool
{
    echo 'W:', $message, "\n";

    return true;
}
set_error_handler('warn_capture');
switch (1) {
    case 1:
        continue;
}
echo "after\n";
--EXPECT--
W:"continue" targeting switch is equivalent to "break"
after
