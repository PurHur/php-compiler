--TEST--
Language: debug_backtrace() closure/arrow function field is {closure} (#23184)
--FILE--
<?php
$f = function () {
    return debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)[0]['function'];
};
echo $f(), "\n";

$g = fn () => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)[0]['function'];
echo $g(), "\n";

$outer = function () {
    $inner = function () {
        return debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)[0]['function'];
    };

    return $inner();
};
echo $outer(), "\n";

ob_start();
(function () {
    debug_print_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
})();
$out = ob_get_clean();
echo (false !== strpos($out, '{closure}')) ? "print-ok\n" : "print-bad\n";
--EXPECT--
{closure}
{closure}
{closure}
print-ok
