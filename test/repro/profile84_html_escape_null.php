<?php
declare(strict_types=1);
/**
 * Issue #21351 — htmlspecialchars/htmlentities/nl2br/addslashes(null) on PROFILE=8.4.
 */
$failed = 0;
foreach (['htmlspecialchars', 'htmlentities', 'nl2br', 'addslashes'] as $fn) {
    try {
        $fn(null);
        echo "$fn: FAIL expected TypeError\n";
        ++$failed;
    } catch (TypeError $e) {
        echo "$fn: ok\n";
    }
}
exit($failed > 0 ? 1 : 0);
