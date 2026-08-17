<?php
/**
 * #23588 — str_ireplace / substr_replace / strtr Reflection types vs php-src
 * ext/standard/string.stub.php (str_replace + substr_count already matched).
 */
foreach (['str_replace', 'str_ireplace', 'substr_replace', 'strtr', 'substr_count'] as $fn) {
    $rf = new ReflectionFunction($fn);
    echo $fn, ' ret=', $rf->hasReturnType() ? (string) $rf->getReturnType() : '(none)', "\n";
    foreach ($rf->getParameters() as $p) {
        $t = $p->hasType() ? (string) $p->getType() : '(none)';
        $ref = $p->isPassedByReference() ? '&' : '';
        $opt = $p->isOptional() ? '?' : '';
        echo '  ', $ref, $opt, $p->getName(), ' ', $t, "\n";
    }
}
