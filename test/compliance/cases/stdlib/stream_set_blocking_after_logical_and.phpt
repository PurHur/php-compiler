--TEST--
stream_set_blocking() after logical && — nested var_dump arg not phi-clobbered (#9292)
--FILE--
<?php
$f = tmpfile();
$meta = stream_get_meta_data($f);
var_dump(
    isset($meta['wrapper_type'])
    && isset($meta['stream_type'])
    && isset($meta['seekable'])
);
var_dump(stream_set_blocking($f, true));
fclose($f);
--EXPECT--
bool(true)
bool(true)
