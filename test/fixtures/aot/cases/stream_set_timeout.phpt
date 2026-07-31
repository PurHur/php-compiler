--TEST--
AOT: stream_set_timeout() / stream_set_chunk_size() link + php-src-strict timeout (issue #3754, #25924)
--FILE--
<?php
// php://memory avoids pre-existing plainfile fopen AOT segfault on this host;
// chunk_size must link __compiler_stream_set_* and not hang (try/catch NestedJIT).
$fp = fopen('php://memory', 'r+');
stream_set_chunk_size($fp, 4096);
echo stream_set_timeout($fp, 1) ? '1' : '0', "\n";
fclose($fp);
--EXPECT--
0
