<?php
// Repro #23957 — PHP 8.4 exit()/die() single $status; no phantom $message.
$r = new ReflectionFunction('exit');
echo 'exit n=', $r->getNumberOfParameters(), ' required=', $r->getNumberOfRequiredParameters(), "\n";
foreach ($r->getParameters() as $p) {
    echo 'exit p=', $p->getName(), ' opt=', ($p->isOptional() ? 'y' : 'n'), "\n";
}
$rd = new ReflectionFunction('die');
echo 'die n=', $rd->getNumberOfParameters(), "\n";
foreach ($rd->getParameters() as $p) {
    echo 'die p=', $p->getName(), "\n";
}

try {
    exit(0, 'bye');
    echo "two-arg: no throw\n";
} catch (Throwable $e) {
    echo 'two-arg: ', get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    exit(message: 'bye');
    echo "named-message: no throw\n";
} catch (Throwable $e) {
    echo 'named-message: ', get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    // Should print and exit 0 — keep last so earlier catches run.
    exit(status: 'ok-status');
} catch (Throwable $e) {
    echo 'named-status: ', get_class($e), ': ', $e->getMessage(), "\n";
}
