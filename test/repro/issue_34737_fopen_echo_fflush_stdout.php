<?php

/**
 * AOT: fopen + echo must not abort at exit (#34737).
 *
 * ObStorageLlvm::emitFflushStdout must load FILE* from @stdout before fflush(3).
 * php-src: main/output.c php_output_flush → fflush(stdout)
 */
$f = fopen('php://memory', 'r+');
fwrite($f, 'x');
rewind($f);
echo stream_get_contents($f), "\n";
$g = fopen('/tmp/phpc_34737_'.getmypid().'.txt', 'w+');
fwrite($g, 'y');
rewind($g);
echo stream_get_contents($g), "\n";
fclose($f);
fclose($g);
echo "ok\n";
