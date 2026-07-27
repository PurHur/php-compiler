<?php
// Repro #23687 — exit/die must not be ReflectionFunction-visible on Zend 8.2 profile.
foreach (['exit', 'die'] as $c) {
    echo 'exists ', $c, '=', function_exists($c) ? 'yes' : 'no', "\n";
    try {
        $r = new ReflectionFunction($c);
        $params = array_map(static fn ($p) => $p->getName(), $r->getParameters());
        echo 'rf ', $c, '=ok params=', implode(',', $params), "\n";
    } catch (Throwable $e) {
        echo 'rf ', $c, '=', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
