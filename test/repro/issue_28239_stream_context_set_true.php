<?php
/** Issue #28239 — stream_context_set_options/set_params Reflection return true (PROFILE≥8.3). */
foreach (['stream_context_set_options', 'stream_context_set_params'] as $f) {
    $r = new ReflectionFunction($f);
    echo $f, ' ret=', $r->hasReturnType() ? (string) $r->getReturnType() : '?', PHP_EOL;
}
$c = stream_context_create();
echo 'opts=', stream_context_set_options(context: $c, options: ['http' => ['method' => 'GET']]) ? '1' : '0', PHP_EOL;
echo 'params=', stream_context_set_params(context: $c, params: []) ? '1' : '0', PHP_EOL;
