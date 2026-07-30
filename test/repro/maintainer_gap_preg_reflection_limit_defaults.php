<?php
/**
 * #24969 — preg_replace/callback/filter/split Reflection limit=-1; count=NULL.
 * php-src: ext/pcre/php_pcre.stub.php
 */
foreach (['preg_replace', 'preg_replace_callback', 'preg_filter', 'preg_split'] as $f) {
    $r = new ReflectionFunction($f);
    $parts = [];
    foreach ($r->getParameters() as $p) {
        $d = $p->isDefaultValueAvailable()
            ? var_export($p->getDefaultValue(), true)
            : ($p->isOptional() ? 'OPT' : 'REQ');
        $parts[] = $p->getName().'='.$d;
    }
    echo $f, '(', implode(', ', $parts), ")\n";
}
