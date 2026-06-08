<?php

enum E: string { case A = 'x'; }

echo 'class_exists=', (int) class_exists('StreamBucket', false), PHP_EOL;
echo 'stream_bucket_new=', (int) function_exists('stream_bucket_new'), PHP_EOL;

$f = fopen('php://memory', 'r+');
$b = stream_bucket_new($f, 'hello');
echo 'class=', get_class($b), PHP_EOL;
echo 'data=', $b->data, PHP_EOL;
echo 'bucket_is_resource=', (int) is_resource($b->bucket), PHP_EOL;

try {
    stream_bucket_new($f, E::A);
    echo "enum_buffer_uncaught\n";
} catch (TypeError $e) {
    echo 'enum_buffer=', $e->getMessage(), PHP_EOL;
}
