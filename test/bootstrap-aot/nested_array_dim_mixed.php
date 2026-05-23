<?php

declare(strict_types=1);

/**
 * Nested string-key dims with scalar leaves (issues #827, #1072).
 */

$a = [
    'meta' => [
        'count' => 3,
        'label' => 'ok',
        'flags' => true,
    ],
];
echo $a['meta']['count'], "\n";
echo $a['meta']['label'], "\n";
echo $a['meta']['flags'] ? '1' : '0';
echo "\n";
