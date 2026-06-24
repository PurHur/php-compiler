<?php

declare(strict_types=1);

echo defined('STREAM_META_SEEKABLE') ? '1' : '0', "\n";
echo STREAM_META_SEEKABLE, "\n";
$fp = fopen('php://memory', 'r+');
echo stream_supports($fp, STREAM_META_SEEKABLE) ? '1' : '0', "\n";
fclose($fp);
