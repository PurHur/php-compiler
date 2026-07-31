<?php
try {
    intval('10', new stdClass);
    echo "intval: no throw\n";
} catch (Throwable $e) {
    echo 'intval: ', $e->getMessage(), "\n";
}
try {
    $x = 1;
    settype($x, new stdClass);
    echo "settype: no throw\n";
} catch (Throwable $e) {
    echo 'settype: ', $e->getMessage(), "\n";
}
try {
    intval('10', new DateTime('now'));
    echo "intval-dt: no throw\n";
} catch (Throwable $e) {
    echo 'intval-dt: ', $e->getMessage(), "\n";
}
try {
    $x = 1;
    settype($x, new DateTime('now'));
    echo "settype-dt: no throw\n";
} catch (Throwable $e) {
    echo 'settype-dt: ', $e->getMessage(), "\n";
}