<?php

declare(strict_types=1);

// Maintainer gap #12190 — introspection builtins with leading backslash (php-src-strict).
define('GAP_CONST', 42);

class GapParent
{
}

class GapChild extends GapParent
{
}

$checks = [
    is_a('\\GapChild', '\\GapParent', true) === true,
    get_parent_class('\\GapChild') === 'GapParent',
    constant('\\GAP_CONST') === 42,
    constant('\\PHP_VERSION') !== '',
    is_array(get_class_vars('\\stdClass')),
];

foreach ($checks as $i => $ok) {
    if (!$ok) {
        fwrite(STDERR, "fail: introspection check {$i}\n");
        exit(1);
    }
}

echo "ok\n";
