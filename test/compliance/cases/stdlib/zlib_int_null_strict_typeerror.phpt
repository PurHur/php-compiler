--TEST--
stdlib zlib int params — strict_types TypeError on null (#19948)
--FILE--
<?php
declare(strict_types=1);

foreach ([
    'gzcompress level' => fn() => gzcompress("hi", null),
    'zlib_encode level' => fn() => zlib_encode("hi", ZLIB_ENCODING_GZIP, null),
    'gzdeflate level' => fn() => gzdeflate("hi", null),
    'gzencode level' => fn() => gzencode("hi", null),
    'gzdecode maxlen' => fn() => gzdecode(gzcompress("hi"), null),
    'gzuncompress maxlen' => fn() => gzuncompress(gzcompress("hi"), null),
] as $name => $fn) {
    try {
        $fn();
        echo "$name: no error\n";
    } catch (TypeError $e) {
        echo "$name: TypeError\n";
    }
}
?>
--EXPECT--
gzcompress level: TypeError
zlib_encode level: TypeError
gzdeflate level: TypeError
gzencode level: TypeError
gzdecode maxlen: TypeError
gzuncompress maxlen: TypeError
