<?php
/**
 * #28920 — crypt Reflection: $salt required (basic_functions.stub.php / crypt.c).
 */
$r = new ReflectionFunction('crypt');
$p = $r->getParameters()[1];
echo 'salt_optional=', $p->isOptional() ? '1' : '0', "\n";
echo 'req=', $r->getNumberOfRequiredParameters(), "\n";
echo 'types=', (string) $r->getParameters()[0]->getType(), ' ', (string) $p->getType(), "\n";
try {
    crypt('x');
    echo "argc=ok\n";
} catch (Throwable $e) {
    echo 'argc=', get_class($e), "\n";
}
