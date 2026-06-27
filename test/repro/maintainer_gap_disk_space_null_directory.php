<?php

// Repro for #12619 — disk_*_space(null) must return false, not cwd stats (php-src filestat.c).
$fail = 0;
$free = disk_free_space(null);
if (false !== $free) {
    echo 'fail: disk_free_space(null) returned ', var_export($free, true), ", expected false\n";
    ++$fail;
}
$total = disk_total_space(null);
if (false !== $total) {
    echo 'fail: disk_total_space(null) returned ', var_export($total, true), ", expected false\n";
    ++$fail;
}
if (0 === $fail) {
    echo "ok\n";
}
