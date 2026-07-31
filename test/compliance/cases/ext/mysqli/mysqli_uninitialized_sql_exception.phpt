--TEST--
ext/mysqli uninitialized link throws mysqli_sql_exception (#21815, ext/mysqli/mysqli_api.c)
--ENV--
PHP_COMPILER_ENABLE_MYSQLI=1
--FILE--
<?php
declare(strict_types=1);

function catch_class(callable $fn): string {
    try {
        $fn();
        return 'none';
    } catch (mysqli_sql_exception $e) {
        return 'mysqli_sql_exception:'.$e->getMessage();
    } catch (Throwable $e) {
        return get_class($e).':'.$e->getMessage();
    }
}

echo catch_class(static fn () => (new mysqli())->query('SELECT 1')), "\n";
echo catch_class(static fn () => mysqli_real_escape_string(new mysqli(), 'x')), "\n";
?>
--EXPECT--
mysqli_sql_exception:mysqli object is not fully initialized
mysqli_sql_exception:mysqli object is not fully initialized
