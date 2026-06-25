<?php

declare(strict_types=1);

$path = '/nope/' . getmypid();

try {
    $nested = chown($path, getmyuid());
    echo 'nested: ' . var_export($nested, true) . "\n";
} catch (Throwable $e) {
    echo 'nested error: ' . get_class($e) . ': ' . $e->getMessage() . "\n";
}

$u = getmyuid();
try {
    $control = chown($path, $u);
    echo 'control: ' . var_export($control, true) . "\n";
} catch (Throwable $e) {
    echo 'control error: ' . get_class($e) . ': ' . $e->getMessage() . "\n";
}

try {
    $nestedGrp = chgrp($path, getmygid());
    echo 'chgrp nested: ' . var_export($nestedGrp, true) . "\n";
} catch (Throwable $e) {
    echo 'chgrp nested error: ' . get_class($e) . ': ' . $e->getMessage() . "\n";
}
