<?php

declare(strict_types=1);

$fp = fopen('php://memory', 'r+');
fwrite($fp, "hello\n");
rewind($fp);
stream_filter_append($fp, 'string.toupper', STREAM_FILTER_READ);
$toupper = fgets($fp);
$ok = 'HELLO'."\n" === $toupper;
fclose($fp);

$fp2 = fopen('php://memory', 'r+');
fwrite($fp2, "hello\n");
rewind($fp2);
stream_filter_append($fp2, 'string.rot13', STREAM_FILTER_READ);
$rot13 = fgets($fp2);
$ok = $ok && 'uryyb'."\n" === $rot13;
fclose($fp2);

echo $ok ? "ok\n" : "fail toupper=".var_export($toupper, true)." rot13=".var_export($rot13, true)."\n";
exit($ok ? 0 : 1);
