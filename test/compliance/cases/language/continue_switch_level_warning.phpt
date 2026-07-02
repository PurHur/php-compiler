--TEST--
continue N targeting switch warning includes level (issue #14915, Zend zend_compile.c)
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
        for (;;) {
            continue 2;
        }
}
echo "after\n";
--EXPECT--
W:"continue 2" targeting switch is equivalent to "break 2"
after
