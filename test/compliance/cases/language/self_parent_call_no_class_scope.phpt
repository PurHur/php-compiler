--TEST--
Language: self::/parent:: call with no class scope — Zend access message (#29096, zend_execute.c)
--FILE--
<?php
foreach (['self', 'parent', 'static'] as $kw) {
    try {
        eval("{$kw}::foo();");
    } catch (Throwable $e) {
        echo $kw, ': ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
--EXPECT--
self: Error: Cannot access "self" when no class scope is active
parent: Error: Cannot access "parent" when no class scope is active
static: Error: Cannot access "static" when no class scope is active
