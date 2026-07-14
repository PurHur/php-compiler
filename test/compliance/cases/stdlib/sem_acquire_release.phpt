--TEST--
stdlib sem_get/acquire/release round-trip (#3704)
--SKIPIF--
<?php if (!function_exists('sem_get')) { print 'skip sysvsem unavailable'; } ?>
--FILE--
<?php
echo function_exists('sem_get') ? "fn\n" : "no-fn\n";
$key = 0x3704;
$sem = @sem_get($key, 1);
if (false === $sem) {
    echo "get-fail\n";
    exit(0);
}
echo get_class($sem), "\n";
echo sem_acquire($sem) ? "acq\n" : "acq-fail\n";
echo sem_release($sem) ? "rel\n" : "rel-fail\n";
@sem_remove($sem);
echo "done\n";
?>
--EXPECT--
fn
SysvSemaphore
acq
rel
done
