<?php

declare(strict_types=1);

/**
 * Repro for #28169 — PHP_OUTPUT_HANDLER_PROCESSED under PROFILE≥8.4.
 */
echo 'defined=', defined('PHP_OUTPUT_HANDLER_PROCESSED') ? '1' : '0', PHP_EOL;
if (defined('PHP_OUTPUT_HANDLER_PROCESSED')) {
    echo 'val=', PHP_OUTPUT_HANDLER_PROCESSED, PHP_EOL;
}
