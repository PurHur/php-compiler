<?php
// #18817 — touch/unlink/rename/mkdir(null) TypeError on 8.4 forward profile.
// Run with: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/maintainer_gap_filestat_null_forward_profile.php

$failed = 0;
foreach (['touch', 'unlink', 'rename', 'mkdir', 'filesize'] as $fn) {
    try {
        if ('rename' === $fn) {
            rename(null, 'b');
        } elseif ('mkdir' === $fn) {
            @mkdir(null);
        } elseif ('touch' === $fn) {
            touch(null);
        } elseif ('filesize' === $fn) {
            filesize(null);
        } else {
            @unlink(null);
        }
        echo "$fn: uncaught\n";
        ++$failed;
    } catch (TypeError $e) {
        echo $fn.': '.$e->getMessage()."\n";
    }
}

exit($failed > 0 ? 1 : 0);
