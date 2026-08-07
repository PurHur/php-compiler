<?php
/**
 * #27797 — stream_bucket_* Reflection StreamBucket shapes under PROFILE≥8.4.
 */
foreach (['stream_bucket_new', 'stream_bucket_make_writeable', 'stream_bucket_append', 'stream_bucket_prepend'] as $f) {
    $r = new ReflectionFunction($f);
    $ps = [];
    foreach ($r->getParameters() as $p) {
        $ps[] = ($p->hasType() ? (string) $p->getType() : '') . ' $' . $p->getName();
    }
    echo $f, '(', implode(', ', $ps), '): ', $r->hasReturnType() ? (string) $r->getReturnType() : '', "\n";
}
