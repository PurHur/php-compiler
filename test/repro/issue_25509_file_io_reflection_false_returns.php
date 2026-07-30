<?php
declare(strict_types=1);

// #25509: file I/O Reflection return unions + file_put_contents data/context metadata.
foreach (['file_get_contents', 'file_put_contents', 'fread', 'fwrite', 'fgets'] as $fn) {
    $r = new ReflectionFunction($fn);
    echo $fn, ' ret=', (string) $r->getReturnType(), "\n";
}
$r = new ReflectionFunction('file_put_contents');
foreach ($r->getParameters() as $p) {
    echo '  ', $p->getName(), ' type=', $p->hasType() ? (string) $p->getType() : '?', ' opt=', $p->isOptional() ? 'Y' : 'N';
    if ($p->isOptional() && $p->isDefaultValueAvailable()) {
        echo ' def=', var_export($p->getDefaultValue(), true);
    }
    echo "\n";
}
echo 'missing=', var_export(@file_get_contents('/no/such/phpc-fget-25509.txt')), "\n";
