<?php
/**
 * #36382 — coalesce defaults merge must not segfault (isset-foreach on array param does).
 */
function merge_coalesce(array $options): array
{
    return [
        'routeParser' => $options['routeParser'] ?? 'FastRoute\\RouteParser\\Std',
        'dataGenerator' => $options['dataGenerator'] ?? 'FastRoute\\DataGenerator\\GroupCountBased',
        'dispatcher' => $options['dispatcher'] ?? 'FastRoute\\Dispatcher\\GroupCountBased',
        'routeCollector' => $options['routeCollector'] ?? 'FastRoute\\RouteCollector',
    ];
}

$full = [
    'routeParser' => 'RP',
    'dataGenerator' => 'DG',
    'dispatcher' => 'DI',
    'routeCollector' => 'RC',
];
$partial = ['dispatcher' => 'DI'];

$a = merge_coalesce($full);
echo count($a), ':', $a['routeParser'], ':', $a['dispatcher'], "\n";
$b = merge_coalesce($partial);
echo count($b), ':', $b['routeParser'], ':', $b['dispatcher'], "\n";
echo "OK\n";
