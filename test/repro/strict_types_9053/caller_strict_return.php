<?php
declare(strict_types=1);
require __DIR__.'/callee_return.php';
try {
    var_dump(returnsInt());
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
