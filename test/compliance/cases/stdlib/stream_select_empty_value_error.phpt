--TEST--
stdlib stream_select() empty stream arrays — ValueError (ext/standard/streams.c, #11818)
--FILE--
<?php
$r = [];
$w = null;
$e = null;
try {
    stream_select($r, $w, $e, 0, 0);
    echo "unexpected\n";
} catch (ValueError $ex) {
    echo $ex->getMessage(), "\n";
}
--EXPECT--
No stream arrays were passed
