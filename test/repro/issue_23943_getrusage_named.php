<?php
/**
 * #23943 — getrusage Zend stub name is `mode` (ext/standard/basic_functions.stub.php).
 * InternalArgInfo still uses `who`.
 */
$r = new ReflectionFunction('getrusage');
foreach ($r->getParameters() as $p) {
    echo 'param=', $p->getName(), ' opt=', $p->isOptional() ? 'Y' : 'N', "\n";
}

try {
    $usage = getrusage(mode: 1);
    echo is_array($usage) ? "named_ok\n" : "named_bad\n";
} catch (Throwable $e) {
    echo 'named_err=', $e->getMessage(), "\n";
}

try {
    getrusage(who: 1);
    echo "legacy_who_accepted\n";
} catch (Throwable $e) {
    echo 'legacy_who=', $e->getMessage(), "\n";
}
