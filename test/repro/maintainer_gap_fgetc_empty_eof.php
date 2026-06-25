<?php

declare(strict_types=1);

/** Issue #11711 — fgetc() at EOF on empty stream returns false (ext/standard/streams.c). */
$h = fopen('php://memory', 'r+');
$byte = fgetc($h);
echo 'byte=', var_export($byte, true), "\n";
echo 'eof=', feof($h) ? 'true' : 'false', "\n";
fclose($h);
