--TEST--
stdlib stream_set_chunk_size() null $size under strict_types — TypeError (#16525, ext/standard/streams.c)
--FILE--
<?php
declare(strict_types=1);
$fp = fopen('php://memory', 'r+');
try {
    stream_set_chunk_size($fp, null);
    echo "no exception\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
?>
--EXPECT--
TypeError
