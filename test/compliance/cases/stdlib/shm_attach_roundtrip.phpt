--TEST--
stdlib shm_attach()/shm_get_var()/shm_put_var() round-trip (#6436)
--SKIPIF--
<?php if (!function_exists('shm_attach')) { print 'skip sysvshm unavailable'; } ?>
--FILE--
<?php
echo function_exists('shm_attach') ? "fn\n" : "no-fn\n";
$key = 0x6436;
$shm_id = @shm_attach($key, 1024, 0644);
if (false === $shm_id) {
    echo "attach-fail\n";
    exit(0);
}
echo get_class($shm_id), "\n";
@shm_remove_var($shm_id, 1);
shm_put_var($shm_id, 1, 'hello');
echo shm_get_var($shm_id, 1), "\n";
shm_remove_var($shm_id, 1);
shm_detach($shm_id);
echo "done\n";
?>
--EXPECT--
fn
SysvSharedMemory
hello
done
