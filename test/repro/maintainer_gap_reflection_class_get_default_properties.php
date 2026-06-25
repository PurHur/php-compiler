<?php

declare(strict_types=1);

class ReflectionDefaultProbeC
{
    public int $count = 5;
    private string $label = 'probe';
    public int $unset;
}

$r = new ReflectionClass(ReflectionDefaultProbeC::class);
$defaults = $r->getDefaultProperties();
if (!is_array($defaults)) {
    echo "not_array\n";
    exit(1);
}
if (5 !== $defaults['count'] || 'probe' !== $defaults['label']) {
    echo 'bad:', var_export($defaults, true), "\n";
    exit(1);
}
if (isset($defaults['unset'])) {
    echo "unset_present\n";
    exit(1);
}
echo "defaults_ok\n";
