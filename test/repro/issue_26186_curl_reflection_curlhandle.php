<?php
/** Repro for #26186 — curl_init/setopt/exec/close Reflection CurlHandle stubs. */
foreach (['curl_init', 'curl_setopt', 'curl_exec', 'curl_close'] as $f) {
    $r = new ReflectionFunction($f);
    $ps = [];
    foreach ($r->getParameters() as $p) {
        $t = $p->hasType() ? (string) $p->getType() . ' ' : '';
        $bit = $t . '$' . $p->getName();
        if ($p->isOptional() && $p->isDefaultValueAvailable()) {
            $bit .= '=' . var_export($p->getDefaultValue(), true);
        } elseif ($p->isOptional()) {
            $bit .= '=?';
        }
        $ps[] = $bit;
    }
    echo "$f(" . implode(', ', $ps) . ')';
    echo $r->hasReturnType() ? (': ' . (string) $r->getReturnType()) : '';
    echo "\n";
}
$h = curl_init();
echo 'runtime=', get_debug_type($h), "\n";
curl_close($h);
