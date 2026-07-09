--TEST--
Language: {$GLOBALS[const]} interpolation — Undefined global variable not array key (#17482)
--FILE--
<?php
declare(strict_types=1);

function warn_capture(int $errno, string $message): bool
{
    echo 'W:', $message, "\n";

    return true;
}
set_error_handler('warn_capture');

const C = 'w';
echo "{$GLOBALS[C]}";
echo "\n";
$GLOBALS['w'] = 1;
unset($w);
echo "{$GLOBALS['w']}";
echo "\n";
--EXPECT--
W:Undefined global variable $w

W:Undefined global variable $w

