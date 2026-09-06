<?php
declare(strict_types=1);
function expand(array $options = []): string {
    $options += [
        'routeCollector' => 'RC',
        'dispatcher' => 'D',
    ];
    return ($options['routeCollector'] ?? '?') . ':' . ($options['dispatcher'] ?? '?');
}
echo expand(['dispatcher' => 'Custom']), "\n";
echo expand([]), "\n";
echo "ok\n";
