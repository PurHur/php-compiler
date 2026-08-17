<?php
/**
 * #28255 — chop Reflection matches rtrim (string return + typed params)
 * (ext/standard/string.stub.php / basic_functions.stub.php).
 */
$r = new ReflectionFunction('chop');
echo 'return=', $r->hasReturnType() ? (string) $r->getReturnType() : '(none)', "\n";
foreach ($r->getParameters() as $p) {
    echo '$', $p->getName(), ' type=',
        $p->hasType() ? (string) $p->getType() : '(none)',
        ' opt=', $p->isOptional() ? 'y' : 'n', "\n";
}
echo 'pos=[', chop('  a  '), "]\n";
try {
    echo 'na=[', chop(string: '  a  '), "]\n";
} catch (Throwable $e) {
    echo 'na_err=', $e->getMessage(), "\n";
}
