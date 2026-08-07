<?php
/**
 * Issue #28431 — AOT sem_get/acquire/release/remove must not refuse (peer #3704 VM).
 */
$key = 0x28431;
$s = @sem_get($key, 1, 0666, true);
echo $s === false ? "false\n" : "ok\n";
if ($s !== false) {
    echo @sem_acquire($s) ? "acq\n" : "acq_fail\n";
    echo @sem_release($s) ? "rel\n" : "rel_fail\n";
    echo @sem_remove($s) ? "rm\n" : "rm_fail\n";
}
