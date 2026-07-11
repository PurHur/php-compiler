<?php
declare(strict_types=1);

try {
    spl_autoload_register(1);
    fwrite(STDERR, "fail: expected TypeError, got success\n");
    exit(1);
} catch (\TypeError $e) {
    echo 'ok: ', $e->getMessage(), "\n";
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, 'fail: expected TypeError, got '.get_class($e).': '.$e->getMessage()."\n");
    exit(1);
}
