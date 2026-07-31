<?php
// Repro #25845 — hash_hkdf return + stream_context_set_option Reflection (re-#25018/#23939)
foreach (['hash_hkdf', 'stream_context_set_option'] as $f) {
    $r = new ReflectionFunction($f);
    $ps = [];
    foreach ($r->getParameters() as $p) {
        $t = $p->hasType() ? (string) $p->getType() : '-';
        $extra = '';
        if ($p->isDefaultValueAvailable()) {
            try {
                $extra = '='.var_export($p->getDefaultValue(), true);
            } catch (Throwable $e) {
                $extra = '=?';
            }
        } elseif ($p->isOptional()) {
            $extra = '=?';
        }
        $ps[] = ($p->isPassedByReference() ? '&' : '').$p->getName().':'.$t.$extra;
    }
    $ret = $r->hasReturnType() ? (string) $r->getReturnType() : '-';
    echo $f.' => '.$ret.' :: '.implode(', ', $ps)."\n";
}
