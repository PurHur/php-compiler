<?php
/**
 * Issue #22097 — SplFileObject::fputcsv() named escape corrupts line (no newline).
 */
$f = new SplFileObject('php://memory', 'w+');
$f->fputcsv(['a', 'b'], separator: ',', enclosure: '"', escape: '\\');
$f->rewind();
var_export($f->fgets());
