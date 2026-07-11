--TEST--
Language: {$GLOBALS[const]} undefined key warns as global variable not array key (#17482)
--FILE--
<?php

declare(strict_types=1);

const C = 'w';

$message = '';
set_error_handler(static function (int $severity, string $msg) use (&$message): bool {
    $message = $msg;

    return true;
});

echo "{$GLOBALS[C]}";
echo $message, "\n";
--EXPECT--
Undefined global variable $w
