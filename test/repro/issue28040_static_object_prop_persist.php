<?php
/**
 * Function-static object property writes must persist across calls (#28040).
 *
 * Zend: 1|2|3. Prior bug: frame teardown releaseDirectObject through the
 * DECLARE_FUNCTION_STATIC INDIRECT CV dropped the static object's refcount
 * and destroyForGc wiped properties → 1|2|1.
 */
function f(): int
{
    static $x = new stdClass;
    $x->n = ($x->n ?? 0) + 1;

    return $x->n;
}

function ids(): int
{
    static $x = new stdClass;

    return spl_object_id($x);
}

echo f(), '|', f(), '|', f(), "\n";
echo ids(), '|', ids(), '|', ids(), "\n";
