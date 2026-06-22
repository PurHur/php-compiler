<?php

declare(strict_types=1);

$g = (function () {
    yield 1;
})();
$g->next();
try {
    $g->throw(new Exception('x'));
    echo "active: no\n";
} catch (Exception $e) {
    echo 'active: ', $e->getMessage(), "\n";
}
