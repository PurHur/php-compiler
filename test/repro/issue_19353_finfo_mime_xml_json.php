<?php
declare(strict_types=1);

$f = new finfo(FILEINFO_MIME_TYPE);
foreach ([
    '<?xml version="1.0"?><a/>',
    '{"a":1}',
    '<html><body>x</body></html>',
] as $s) {
    echo $f->buffer($s), "\n";
}
