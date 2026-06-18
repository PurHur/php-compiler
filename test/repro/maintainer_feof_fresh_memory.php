<?php

declare(strict_types=1);

/** Issue #9283 — feof() on fresh php://memory (ext/standard/streams.c). */
$h = fopen('php://memory', 'r+');
var_dump(feof($h));
fwrite($h, 'x');
rewind($h);
var_dump(feof($h));
fgetc($h);
var_dump(feof($h));
