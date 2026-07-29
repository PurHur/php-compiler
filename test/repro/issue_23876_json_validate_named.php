<?php
/** Repro for #23876 — json_validate Reflection names + named args (PROFILE=8.4). */
$rf = new ReflectionFunction('json_validate');
$n = [];
foreach ($rf->getParameters() as $p) {
    $def = '';
    if ($p->isOptional() && $p->isDefaultValueAvailable()) {
        $def = '=' . var_export($p->getDefaultValue(), true);
    } elseif ($p->isOptional()) {
        $def = '=';
    }
    $n[] = $p->getName() . $def;
}
echo 'req=', $rf->getNumberOfRequiredParameters(),
    ' total=', $rf->getNumberOfParameters(),
    ' [', implode(',', $n), "]\n";

try {
    echo 'named_json=', var_export(json_validate(json: '{"a":1}'), true), "\n";
    echo 'named_depth=', var_export(json_validate('{"a":1}', depth: 512), true), "\n";
    echo 'named_flags=', var_export(json_validate('{"a":1}', flags: 0), true), "\n";
    echo 'pos=', var_export(json_validate('{"a":1}'), true), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
