<?php

declare(strict_types=1);

/**
 * Repro #13610 — SplTempFileObject::__toString() returns file contents (ext/spl/spl_directory.c).
 */

$f = new SplTempFileObject();
$f->fwrite('hi');
$f->rewind();
echo (string) $f, "\n";
