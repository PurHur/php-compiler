<?php
declare(strict_types=1);
try {
    $r = DateInterval::createFromDateString(null);
    echo 'result:'.var_export($r, true),"\n";
} catch (Throwable $e) {
    echo get_class($e).':'.$e->getMessage(),"\n";
}
