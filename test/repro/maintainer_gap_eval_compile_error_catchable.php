<?php
// #25114 — eval() compile failures via zend_throw_exception must be catchable CompileError.
try {
    eval('class AV { public private(set) string $x; }');
    echo "no_catch\n";
} catch (CompileError $e) {
    echo "caught=", get_class($e), "\n";
    echo "msg=", $e->getMessage(), "\n";
    echo "file_has_eval=", (strpos($e->getFile(), "eval()") !== false ? "1" : "0"), "\n";
} catch (Throwable $e) {
    echo "caught_other=", get_class($e), " msg=", $e->getMessage(), "\n";
}
echo "ok\n";
