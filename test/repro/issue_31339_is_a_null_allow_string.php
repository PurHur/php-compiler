<?php
declare(strict_types=1);
try {
    var_export(is_a(new stdClass(), 'stdClass', null));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    var_export(is_subclass_of(new class extends stdClass {}, 'stdClass', null));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
echo is_a(new stdClass(), 'stdClass') ? "two_arg_ok\n" : "two_arg_fail\n";
