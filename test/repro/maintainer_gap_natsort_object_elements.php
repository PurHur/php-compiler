<?php

declare(strict_types=1);

$a = [new stdClass(), new stdClass()];
try {
    natsort($a);
    echo "natsort_ok=1\n";
} catch (Throwable $e) {
    echo 'natsort_error='.get_class($e).':'.$e->getMessage()."\n";
}
try {
    natcasesort($a);
    echo "natcasesort_ok=1\n";
} catch (Throwable $e) {
    echo 'natcasesort_error='.get_class($e).':'.$e->getMessage()."\n";
}
