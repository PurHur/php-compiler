<?php

declare(strict_types=1);

echo defined('STREAM_SUPPORT_READ') ? 'read-defined' : 'read-missing', "\n";
echo defined('STREAM_SUPPORT_WRITE') ? 'write-defined' : 'write-missing', "\n";

$fp = tmpfile();
echo stream_supports($fp, STREAM_SUPPORT_READ) ? 'read-yes' : 'read-no', "\n";
echo stream_supports($fp, 'read') ? 'str-read-yes' : 'str-read-no', "\n";
echo stream_supports($fp, STREAM_SUPPORT_WRITE) ? 'write-yes' : 'write-no', "\n";
echo stream_supports($fp, 'write') ? 'str-write-yes' : 'str-write-no', "\n";
fclose($fp);
