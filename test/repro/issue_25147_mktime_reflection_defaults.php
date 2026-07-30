<?php
declare(strict_types=1);

foreach (['mktime', 'gmmktime'] as $fn) {
    echo '== ', $fn, " ==\n";
    foreach ((new ReflectionFunction($fn))->getParameters() as $p) {
        echo $p->getName(), ' optional=', $p->isOptional() ? 'yes' : 'no';
        if ($p->isDefaultValueAvailable()) {
            echo ' def=', var_export($p->getDefaultValue(), true);
        }
        echo ' type=', $p->hasType() ? (string) $p->getType() : '-';
        echo "\n";
    }
}
try {
    mktime();
    echo "zero_ok\n";
} catch (ArgumentCountError $e) {
    echo 'zero: ArgumentCountError', "\n";
}
echo 'mktime15=', is_int(mktime(15)) ? 'int' : 'bad', "\n";
echo 'gmmktime15=', is_int(gmmktime(15)) ? 'int' : 'bad', "\n";
