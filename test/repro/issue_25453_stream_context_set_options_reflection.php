<?php

declare(strict_types=1);

// Repro #25453: stream_context_set_options Reflection + named args under PROFILE=8.4
if (!function_exists('stream_context_set_options')) {
    echo "missing\n";
    exit(0);
}

$r = new ReflectionFunction('stream_context_set_options');
echo 'arity=', $r->getNumberOfParameters(),
    ' ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'NONE', "\n";
foreach ($r->getParameters() as $p) {
    echo '  ', $p->getName(),
        ' type=', $p->hasType() ? (string) $p->getType() : '-',
        ' req=', (int) !$p->isOptional(), "\n";
}

$c = stream_context_create();
try {
    var_export(stream_context_set_options(context: $c, options: ['http' => ['method' => 'GET']]));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
