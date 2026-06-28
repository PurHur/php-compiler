<?php

declare(strict_types=1);

$f = new SplTempFileObject();
$f->fwrite("line\n");
$f->rewind();
$line = $f->fgets();
echo $line === "line\n" ? "ok\n" : "fail: ".var_export($line, true)."\n";
