--TEST--
stdlib shmop_open/read/write/size/close/delete round-trip (#3344)
--SKIPIF--
<?php if (!function_exists('shmop_open')) { print 'skip sysvshm unavailable'; } ?>
--FILE--
<?php
echo function_exists('shmop_open') ? "fn\n" : "no-fn\n";
$key = 0x3344;
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
?>
--EXPECT--
fn
Shmop
64
hi
done
