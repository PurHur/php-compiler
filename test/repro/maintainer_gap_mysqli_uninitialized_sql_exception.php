<?php

declare(strict_types=1);

function expect_mysqli_sql_exception(callable $fn, string $label): void
{
    try {
        $fn();
        echo "$label: no exception\n";
    } catch (mysqli_sql_exception $e) {
        echo "$label: mysqli_sql_exception: {$e->getMessage()}\n";
    } catch (Throwable $e) {
        echo "$label: ".get_class($e).": {$e->getMessage()}\n";
    }
}

expect_mysqli_sql_exception(
    static fn () => (new mysqli())->query('SELECT 1'),
    'query_method'
);

expect_mysqli_sql_exception(
    static fn () => mysqli_real_escape_string(new mysqli(), 'x'),
    'real_escape_string'
);
