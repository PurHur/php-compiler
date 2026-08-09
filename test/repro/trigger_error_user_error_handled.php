<?php

declare(strict_types=1);

// #29216 — handled E_USER_ERROR continues; PROFILE≥8.4 emits E_DEPRECATED first.
error_reporting(E_ALL);
set_error_handler(static function (int $errno, string $errstr): bool {
    echo "handler errno=$errno str=$errstr\n";

    return true;
});
trigger_error('warn', E_USER_WARNING);
trigger_error('err', E_USER_ERROR);
echo "survived\n";
