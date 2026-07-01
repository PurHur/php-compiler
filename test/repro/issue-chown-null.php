<?php

$path = sys_get_temp_dir();

foreach (['chown', 'chgrp', 'lchown', 'lchgrp'] as $fn) {
    try {
        $ok = @$fn($path, null);
        echo $fn, ' => ', var_export($ok, true), "\n";
    } catch (Throwable $e) {
        echo $fn, ' => ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
