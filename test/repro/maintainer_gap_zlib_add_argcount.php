<?php

declare(strict_types=1);

foreach (['inflate_add', 'deflate_add'] as $fn) {
    try {
        $fn(null);
        fwrite(STDERR, "{$fn}: no throw\n");
        exit(1);
    } catch (ArgumentCountError $e) {
        echo $e->getMessage(), "\n";
    } catch (Throwable $e) {
        fwrite(STDERR, "{$fn}: ".get_class($e).': '.$e->getMessage()."\n");
        exit(1);
    }
}
