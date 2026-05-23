<?php

declare(strict_types=1);

/**
 * Chained string-key dim fetch on nested array values (issue #827, #107).
 */

$a = ['outer' => ['inner' => 42]];
echo $a['outer']['inner'], "\n";
