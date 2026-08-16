<?php
/** highlight_string(..., null) $return under strict_types */
declare(strict_types=1);
error_reporting(E_ALL);
try {
    var_export(highlight_string('<?php', null));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}
