<?php
/** Repro #28660 — AOT finfo::buffer MIME must match Zend/VM. */
$f = new finfo(FILEINFO_MIME_TYPE);
$m = $f->buffer('hello');
echo 'mime=', $m, '|', strlen((string) $m), "\n";
echo finfo_buffer($f, 'hello'), "\n";
