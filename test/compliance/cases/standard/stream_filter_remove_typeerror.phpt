--TEST--
stdlib stream_filter_remove() — stream resource operand TypeError (#16691, ext/standard/streams.c)
--FILE--
<?php
$fp = fopen('php://memory', 'r+');
stream_filter_append($fp, 'string.toupper', STREAM_FILTER_READ);
try {
    stream_filter_remove($fp);
    echo "no throw\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
fclose($fp);
?>
--EXPECT--
stream_filter_remove(): supplied resource is not a valid stream filter resource
