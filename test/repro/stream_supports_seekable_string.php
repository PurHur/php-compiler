<?php

declare(strict_types=1);

$fp = fopen('php://memory', 'r+');
echo stream_supports($fp, 'seekable') ? 'seekable=true' : 'seekable=false', "\n";
echo stream_supports($fp, 'seek') ? 'seek=true' : 'seek=false', "\n";
fclose($fp);

$tmp = tmpfile();
echo stream_supports($tmp, 'seekable') ? 'tmp-seekable=true' : 'tmp-seekable=false', "\n";
fclose($tmp);
