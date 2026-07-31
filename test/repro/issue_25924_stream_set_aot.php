<?php
// Issue #25924 — AOT must link __compiler_stream_set_*; timeout matches Zend on memory.
$fp = fopen("php://memory", "r+");
stream_set_chunk_size($fp, 4096);
echo stream_set_timeout($fp, 1, 0) ? "1" : "0", "\n";
fclose($fp);
