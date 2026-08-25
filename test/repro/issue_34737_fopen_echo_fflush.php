<?php
// AOT: fopen + echo must exit 0 — emitFflushStdout loads FILE* from @stdout (#34737).
// php-src: main/output.c php_output_flush → fflush(stdout)
$f = fopen('php://memory', 'r+');
echo "ok\n";
