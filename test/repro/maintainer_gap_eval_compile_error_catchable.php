<?php
// #25420 / #25114 — eval() compile failures via zend_throw_exception must be catchable CompileError.
$cases = [
    'class AV { public private(set) string $x; }',
    'class C { public private $x; }',
    'class C { final abstract function f(); }',
    'readonly readonly class C {}',
];
foreach ($cases as $code) {
    try {
        eval($code);
        echo "no_catch code=", $code, "\n";
    } catch (CompileError $e) {
        echo "caught=", get_class($e), "\n";
        echo "msg=", $e->getMessage(), "\n";
        echo "file_has_eval=", (strpos($e->getFile(), "eval()") !== false ? "1" : "0"), "\n";
    } catch (Throwable $e) {
        echo "caught_other=", get_class($e), " msg=", $e->getMessage(), "\n";
    }
}
echo "ok\n";
