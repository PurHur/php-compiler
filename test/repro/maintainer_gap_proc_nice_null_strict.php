<?php
declare(strict_types=1);

try {
    proc_nice(null);
    echo "fail: uncaught\n";
} catch (TypeError $e) {
    echo 'ok: ', $e->getMessage(), "\n";
}
