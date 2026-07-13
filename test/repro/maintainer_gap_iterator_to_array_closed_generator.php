<?php
// php-src ext/spl/php_spl.c — iterator_to_array() on consumed Generator (#18582).
$g = (function () {
    yield 1;
})();
$g->next();
try {
    iterator_to_array($g);
    echo "no throw\n";
} catch (Exception $e) {
    echo $e->getMessage(), "\n";
}
