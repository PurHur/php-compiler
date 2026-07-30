--TEST--
Language: eval() catchable CompileError for multiple access modifiers (#25114, Zend/zend_compile.c)
--FILE--
<?php
try {
    eval('class AV { public private(set) string $x; }');
    echo "no_catch\n";
} catch (CompileError $e) {
    echo "caught=", $e::class, "\n";
    echo "msg=", $e->getMessage(), "\n";
    echo "file_has_eval=", (str_contains($e->getFile(), "eval()") ? "1" : "0"), "\n";
} catch (Throwable $e) {
    echo "caught_other=", $e::class, "\n";
}
echo "ok\n";
--EXPECT--
caught=CompileError
msg=Multiple access type modifiers are not allowed
file_has_eval=1
ok
