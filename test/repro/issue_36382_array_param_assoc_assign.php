<?php
/**
 * #36382 — assigning an assoc array literal into a function parameter then
 * string-key FETCH_DIM (documents residual; FastRoute patch uses $merged instead).
 *
 * php-src: Zend/zend_execute.c ZEND_ASSIGN into CV; zend_hash_find on that CV.
 */
function via_merged_local(array $options = []): void
{
    $merged = ['routeCollector' => 'RC'];
    echo $merged['routeCollector'], "\n";
}

function via_return(array $options = []): array
{
    $options = [
        'routeParser' => $options['routeParser'] ?? 'FastRoute\\RouteParser\\Std',
        'dispatcher' => $options['dispatcher'] ?? 'DI',
        'routeCollector' => $options['routeCollector'] ?? 'RC',
        'dataGenerator' => $options['dataGenerator'] ?? 'DG',
    ];

    return $options;
}

via_merged_local();
$b = via_return(['dispatcher' => 'DI']);
echo count($b), ':', $b['routeParser'], ':', $b['dispatcher'], "\n";
echo "OK\n";
