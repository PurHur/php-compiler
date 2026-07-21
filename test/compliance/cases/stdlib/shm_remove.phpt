--TEST--
stdlib shm_remove() destroys SysV shared memory segment (#21635)
--SKIPIF--
<?php if (!function_exists('shm_attach') || !function_exists('shm_remove')) { print 'skip sysvshm unavailable'; } ?>
--FILE--
<?php
echo function_exists('shm_remove') ? "fn\n" : "no-fn\n";
$key = 0x21635;
$shm_id = @shm_attach($key, 1024, 0644);
if (false === $shm_id) {
    echo "attach-fail\n";
    exit(0);
}
echo get_class($shm_id), "\n";
shm_put_var($shm_id, 1, 'x');
echo shm_remove($shm_id) ? "rm\n" : "rm-fail\n";
// php-src: object remains detachable after IPC_RMID
echo shm_detach($shm_id) ? "detach\n" : "detach-fail\n";
echo "done\n";
?>
--EXPECT--
fn
SysvSharedMemory
rm
detach
done
