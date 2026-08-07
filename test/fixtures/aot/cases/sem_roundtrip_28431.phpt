--TEST--
sem_get/acquire/release/remove thin AOT (#28431)
--FILE--
<?php
$key = 0x28431;
$s = @sem_get($key, 1, 0666, true);
echo $s === false ? "false\n" : "ok\n";
if ($s !== false) {
    echo @sem_acquire($s) ? "acq\n" : "acq_fail\n";
    echo @sem_release($s) ? "rel\n" : "rel_fail\n";
    echo @sem_remove($s) ? "rm\n" : "rm_fail\n";
}
?>
--EXPECT--
ok
acq
rel
rm
