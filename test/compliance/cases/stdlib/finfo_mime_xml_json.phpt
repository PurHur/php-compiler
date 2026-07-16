--TEST--
stdlib finfo FILEINFO_MIME_TYPE XML/JSON/SVG buffers (#19353)
--FILE--
<?php
declare(strict_types=1);

$f = new finfo(FILEINFO_MIME_TYPE);
foreach ([
    '<?xml version="1.0"?><a/>',
    '{"a":1}',
    '<html><body>x</body></html>',
    '  {"a":1}',
    '[1,2]',
    '<svg xmlns="http://www.w3.org/2000/svg"/>',
] as $s) {
    echo $f->buffer($s), "\n";
}

$mime = new finfo(FILEINFO_MIME);
echo $mime->buffer('{"a":1}'), "\n";
--EXPECT--
text/xml
application/json
text/html
application/json
application/json
image/svg+xml
application/json; charset=us-ascii
