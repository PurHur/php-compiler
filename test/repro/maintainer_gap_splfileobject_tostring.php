<?php

declare(strict_types=1);

/**
 * Repro #13610 — SplFileObject::__toString() returns file contents (ext/spl/spl_directory.c).
 */

$f = new SplFileObject('php://memory', 'w+');
$f->fwrite('hi');
$f->rewind();
echo (string) $f, "\n";
