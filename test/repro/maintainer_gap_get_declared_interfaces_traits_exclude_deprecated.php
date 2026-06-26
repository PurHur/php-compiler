<?php

declare(strict_types=1);

// Maintainer gap #12177 — get_declared_* optional $exclude_deprecated (PHP 8.4).

#[\Deprecated]
interface DepDeclaredInterface
{
}

interface OkDeclaredInterface
{
}

#[\Deprecated]
trait DepDeclaredTrait
{
}

trait OkDeclaredTrait
{
}

#[\Deprecated]
class DepDeclaredClass
{
}

class OkDeclaredClass
{
}

$ifaceAll = get_declared_interfaces();
$ifaceFiltered = get_declared_interfaces(true);
$traitAll = get_declared_traits();
$traitFiltered = get_declared_traits(true);
$classAll = get_declared_classes();
$classFiltered = get_declared_classes(true);

$checks = [
    in_array('DepDeclaredInterface', $ifaceAll, true),
    !in_array('DepDeclaredInterface', $ifaceFiltered, true),
    in_array('OkDeclaredInterface', $ifaceFiltered, true),
    in_array('DepDeclaredTrait', $traitAll, true),
    !in_array('DepDeclaredTrait', $traitFiltered, true),
    in_array('OkDeclaredTrait', $traitFiltered, true),
    in_array('DepDeclaredClass', $classAll, true),
    !in_array('DepDeclaredClass', $classFiltered, true),
    in_array('OkDeclaredClass', $classFiltered, true),
];

foreach ($checks as $i => $ok) {
    if (!$ok) {
        echo "fail: exclude_deprecated check {$i}\n";
        exit(1);
    }
}

echo "ok\n";
