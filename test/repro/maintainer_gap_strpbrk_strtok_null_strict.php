<?php
declare(strict_types=1);

try {
    var_export(strpbrk(null, 'a'));
    echo " bad:strpbrk:uncaught\n";
} catch (TypeError $e) {
    echo 'ok:strpbrk:', $e->getMessage(), "\n";
}

try {
    var_export(strtok(null, ' '));
    echo " bad:strtok:uncaught\n";
} catch (TypeError $e) {
    echo 'ok:strtok:', $e->getMessage(), "\n";
}
