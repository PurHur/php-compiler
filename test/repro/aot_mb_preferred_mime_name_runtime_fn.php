<?php

declare(strict_types=1);

/**
 * Opaque runtime encoding for mb_preferred_mime_name (#35275).
 * Locals assigned from string literals still fold; function return does not.
 */
function enc(): string
{
    return 'UTF-8';
}

var_dump(mb_preferred_mime_name(enc()));
var_dump(mb_preferred_mime_name((static function (): string {
    return 'ASCII';
})()));
