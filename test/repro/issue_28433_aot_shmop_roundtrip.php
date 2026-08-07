<?php
/**
 * Issue #28433 — AOT shmop size/read/write round-trip after #27408 open path.
 */
$key = 0x28433;
$id = @shmop_open($key, 'c', 0644, 64);
if (false === $id) {
    echo "open-fail\n";
    exit(0);
}
echo get_class($id), "\n";
echo shmop_size($id), "\n";
shmop_write($id, 'hi', 0);
echo shmop_read($id, 0, 2), "\n";
shmop_close($id);
@shmop_delete($id);
echo "done\n";
