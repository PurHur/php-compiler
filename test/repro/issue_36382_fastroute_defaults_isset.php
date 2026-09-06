<?php
/**
 * #36382 — assoc defaults via a fresh local (not reassignment onto the array param).
 * FastRoute simpleDispatcher shape after patch-fastroute-options-plus-36382.php.
 */
function merge_coalesce(array $options): array
{
    $merged = [
        'routeParser' => $options['routeParser'] ?? 'FastRoute\\RouteParser\\Std',
        'dataGenerator' => $options['dataGenerator'] ?? 'FastRoute\\DataGenerator\\GroupCountBased',
        'dispatcher' => $options['dispatcher'] ?? 'FastRoute\\Dispatcher\\GroupCountBased',
        'routeCollector' => $options['routeCollector'] ?? 'FastRoute\\RouteCollector',
    ];

    return $merged;
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
