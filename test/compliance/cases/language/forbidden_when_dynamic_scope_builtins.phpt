--TEST--
Language: compact/extract/get_defined_vars ForbiddenWhenDynamic (#23591)
--FILE--
<?php
function t_compact_dynamic(): void
{
    $x = 1;
    $fn = 'compact';
    try {
        $fn('x');
        echo "compact dynamic ALLOWED\n";
    } catch (Throwable $e) {
        echo get_class($e), ': ', $e->getMessage(), "\n";
    }
}

function t_extract_dynamic(): void
{
    $arr = ['a' => 1];
    $fn = 'extract';
    try {
        $fn($arr);
        echo "extract dynamic ALLOWED\n";
    } catch (Throwable $e) {
        echo get_class($e), ': ', $e->getMessage(), "\n";
    }
}

function t_gdv_dynamic(): void
{
    $fn = 'get_defined_vars';
    try {
        $fn();
        echo "get_defined_vars dynamic ALLOWED\n";
    } catch (Throwable $e) {
        echo get_class($e), ': ', $e->getMessage(), "\n";
    }
}

function t_compact_direct(): void
{
    $x = 1;
    $r = compact('x');
    echo isset($r['x']) && 1 === $r['x'] ? "compact direct OK\n" : "compact direct FAIL\n";
}

t_compact_dynamic();
t_extract_dynamic();
t_gdv_dynamic();
t_compact_direct();
--EXPECT--
Error: Cannot call compact() dynamically
Error: Cannot call extract() dynamically
Error: Cannot call get_defined_vars() dynamically
compact direct OK
