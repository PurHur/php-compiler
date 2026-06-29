<?php

declare(strict_types=1);

/**
 * Issue #13630 — builtin stub enums must not register on Zend 8.2 reference profile.
 */

$checks = [
    'PadType' => enum_exists('PadType', false),
    'StringTrimMode' => enum_exists('StringTrimMode', false),
    'MemoryUsage' => enum_exists('MemoryUsage', false),
];

$declared = get_declared_classes();
$padInDeclared = in_array('PadType', $declared, true);

$failed = false;
foreach ($checks as $name => $exists) {
    echo $name, ':', $exists ? 'true' : 'false', "\n";
    if ($exists) {
        $failed = true;
    }
}

echo 'PadType_in_declared:', $padInDeclared ? 'true' : 'false', "\n";
if ($padInDeclared) {
    $failed = true;
}

exit($failed ? 1 : 0);
