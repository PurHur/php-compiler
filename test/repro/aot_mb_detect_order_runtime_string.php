<?php

declare(strict_types=1);

/**
 * Opaque runtime string for mb_detect_order (#35280).
 */
function enc(): string
{
    return 'UTF-8,ASCII';
}

var_export(mb_detect_order(enc()));
echo "\n";
var_export(mb_detect_order());
echo "\n";
