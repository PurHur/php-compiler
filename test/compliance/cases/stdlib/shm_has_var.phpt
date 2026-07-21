--TEST--
stdlib shm_has_var() before/after put and remove_var (#21634)
--SKIPIF--
<?php if (!function_exists('shm_attach') || !function_exists('shm_has_var')) { print 'skip sysvshm unavailable'; } ?>
--FILE--
<?php
echo function_exists('shm_has_var') ? "fn\n" : "no-fn\n";
$key = 0x21634;
$shm_id = @shm_attach($key, 1024, 0644);
if (false === $shm_id) {
    echo "attach-fail\n";
    exit(0);
}
@shm_remove_var($shm_id, 1);
echo shm_has_var($shm_id, 1) ? "has-before\n" : "no-before\n";
shm_put_var($shm_id, 1, 'hello');
echo shm_has_var($shm_id, 1) ? "has-after\n" : "no-after\n";
shm_remove_var($shm_id, 1);
echo shm_has_var($shm_id, 1) ? "has-rm\n" : "no-rm\n";
shm_detach($shm_id);
echo "done\n";
?>
--EXPECT--
fn
no-before
has-after
no-rm
done
