<?php

declare(strict_types=1);

/**
 * #29065 — unserialize() allowed_classes must yield __PHP_Incomplete_Class for
 * disallowed types (including nested properties). php-src var_unserializer.re.
 */
class Allowed
{
    public int $x = 1;
}

class Forbidden
{
    public int $y = 2;
}

class Outer
{
    public $inner;
}

$payload = serialize([new Allowed(), new Forbidden()]);
$listed = unserialize($payload, ['allowed_classes' => ['Allowed']]);
foreach ($listed as $v) {
    echo is_object($v) ? get_class($v) : var_export($v, true), "\n";
}

$none = unserialize($payload, ['allowed_classes' => false]);
foreach ($none as $v) {
    echo is_object($v) ? get_class($v) : var_export($v, true), "\n";
}

$outer = new Outer();
$outer->inner = new Forbidden();
$nested = unserialize(serialize($outer), ['allowed_classes' => ['Outer']]);
echo get_class($nested), "\n";
echo is_object($nested->inner) ? get_class($nested->inner) : '?', "\n";
