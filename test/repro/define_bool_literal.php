<?php

declare(strict_types=1);

if (!defined('DEFINE_BOOL_TEST')) {
    define('DEFINE_BOOL_TEST', true);
}

echo defined('DEFINE_BOOL_TEST') ? 'ok' : 'fail';
