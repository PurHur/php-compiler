--TEST--
stdlib stream_supports() profile — undefined function before undefined const on 8.2 reference (#17697)
--FILE--
<?php
declare(strict_types=1);

echo defined('STREAM_SUPPORT_READ') ? "read-fail\n" : "read-ok\n";
echo defined('STREAM_SUPPORT_WRITE') ? "write-fail\n" : "write-ok\n";
echo function_exists('stream_supports') ? "fn-fail\n" : "fn-ok\n";

$fp = tmpfile();
try {
    stream_supports($fp, STREAM_SUPPORT_READ);
    echo "no-exception\n";
} catch (Throwable $e) {
    echo $e instanceof Error ? 'Error' : get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
read-ok
write-ok
fn-ok
Error: Call to undefined function stream_supports()
