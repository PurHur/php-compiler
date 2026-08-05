<?php
/**
 * Issue #27884 — grapheme_* Reflection names/returns + named args.
 * php-src: ext/intl/grapheme/grapheme.stub.php
 */
if (!extension_loaded('intl') && !function_exists('grapheme_strlen')) {
    fwrite(STDERR, "skip: host php-intl required\n");
    exit(0);
}
$r = new ReflectionFunction('grapheme_strlen');
echo 'argc=', $r->getNumberOfParameters(), ' ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none', PHP_EOL;
foreach ($r->getParameters() as $p) {
    echo $p->getName(), PHP_EOL;
}
try {
    echo grapheme_strlen(string: 'é'), PHP_EOL;
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), PHP_EOL;
}
