<?php
/** strncmp/strncasecmp null $length under strict_types (#31265) */
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');
foreach (['strncmp', 'strncasecmp'] as $fn) {
    try {
        var_export($fn('a', 'b', null));
        echo "\n";
    } catch (Throwable $e) {
        echo get_class($e).':'.$e->getMessage()."\n";
    }
}
