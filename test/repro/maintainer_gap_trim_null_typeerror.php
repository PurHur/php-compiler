<?php
declare(strict_types=1);

$failed = 0;
foreach (['trim', 'ltrim', 'rtrim', 'chop'] as $fn) {
    try {
        $fn(null);
        echo "$fn: FAIL expected TypeError\n";
        ++$failed;
    } catch (TypeError $e) {
        echo "$fn: ok\n";
    }
}
exit($failed > 0 ? 1 : 0);
