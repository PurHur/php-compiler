--TEST--
finfo_buffer() / finfo::buffer() AOT MIME (#28660)
--FILE--
<?php
$f = new finfo(FILEINFO_MIME_TYPE);
$m = $f->buffer('hello');
echo 'mime=', $m, '|', strlen((string) $m), "\n";
echo finfo_buffer($f, 'hello'), "\n";
--EXPECT--
mime=text/plain|10
text/plain
