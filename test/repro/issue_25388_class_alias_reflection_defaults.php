<?php
// Repro for #25388 — class_alias Reflection required/default vs Zend stubs
$rf = new ReflectionFunction('class_alias');
foreach ($rf->getParameters() as $p) {
    echo $p->getName(),
        ' optional=', $p->isOptional() ? 'Y' : 'N',
        ' type=', $p->hasType() ? (string) $p->getType() : '?';
    if ($p->isDefaultValueAvailable()) {
        echo ' def=', var_export($p->getDefaultValue(), true);
    }
    echo "\n";
}
echo 'required=', $rf->getNumberOfRequiredParameters(), "\n";
class Orig25388 {}
try {
    class_alias(class: 'Orig25388', alias: 'Alias25388');
    echo class_exists('Alias25388') ? 'named=Y' : 'named=N', "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
