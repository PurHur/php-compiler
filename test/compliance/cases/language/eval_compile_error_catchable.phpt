--TEST--
Language: eval() catchable CompileError for zend_throw_exception diagnostics (#25420, #25114, Zend/zend_compile.c)
--FILE--
<?php
$cases = [
    'class AV { public private(set) string $x; }',
    'class C { public private $x; }',
    'class C { final abstract function f(); }',
    'readonly readonly class C {}',
];
foreach ($cases as $code) {
    try {
        eval($code);
        echo "no_catch\n";
    } catch (CompileError $e) {
        echo "caught=", $e::class, "\n";
        echo "msg=", $e->getMessage(), "\n";
        echo "file_has_eval=", (str_contains($e->getFile(), "eval()") ? "1" : "0"), "\n";
    } catch (Throwable $e) {
        echo "caught_other=", $e::class, "\n";
    }
}
echo "ok\n";
--EXPECT--
caught=CompileError
msg=Multiple access type modifiers are not allowed
file_has_eval=1
caught=CompileError
msg=Multiple access type modifiers are not allowed
file_has_eval=1
caught=CompileError
msg=Cannot use the final modifier on an abstract class member
file_has_eval=1
caught=CompileError
msg=Multiple readonly modifiers are not allowed
file_has_eval=1
ok
