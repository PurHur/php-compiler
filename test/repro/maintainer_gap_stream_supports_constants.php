<?php

declare(strict_types=1);

echo 'fn='.(function_exists('stream_supports') ? 'yes' : 'no')."\n";
echo 'lock='.(defined('STREAM_SUPPORT_LOCK') ? 'yes' : 'no')."\n";
echo 'seek='.(defined('STREAM_SUPPORT_SEEK') ? 'yes' : 'no')."\n";
echo 'tell='.(defined('STREAM_SUPPORT_TELL') ? 'yes' : 'no')."\n";

if (!defined('STREAM_SUPPORT_LOCK')) {
    exit(1);
}

$fp = fopen('php://memory', 'r+');
echo 'supports_lock='.(stream_supports($fp, STREAM_SUPPORT_LOCK) ? 'true' : 'false')."\n";
echo 'supports_seek='.(stream_supports($fp, STREAM_SUPPORT_SEEK) ? 'true' : 'false')."\n";
echo 'supports_tell='.(stream_supports($fp, STREAM_SUPPORT_TELL) ? 'true' : 'false')."\n";
