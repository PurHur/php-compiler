--TEST--
Language: {$GLOBALS[const]} undefined global warning — not array key (#17482, Zend/zend_execute.c)
--FILE--
<?php
function warn_capture(int $errno, string $message): bool
{
    echo 'W:', $message, "\n";

    return true;
}
set_error_handler('warn_capture');

const C = 'w';
echo "{$GLOBALS[C]}";
echo "\n";
echo $GLOBALS['w'];
echo "\n";
--EXPECT--
W:Undefined global variable $w

W:Undefined global variable $w

