<?php
/**
 * Issue #27408 — AOT shmop_open/delete/close must not refuse (peer #3344 VM).
 */
$key = 0x27408;
$s = @shmop_open($key, 'c', 0644, 100);
echo $s === false ? "false\n" : "ok\n";
if ($s !== false) {
    shmop_delete($s);
    shmop_close($s);
}
