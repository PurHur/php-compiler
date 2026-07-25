<?php
// #23184 — debug_backtrace closure function field vs Zend {closure}

$f = function () {
    return debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)[0]['function'];
};
echo 'plain: ';
var_export($f());
echo "\n";

$g = fn () => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)[0]['function'];
echo 'arrow: ';
var_export($g());
echo "\n";

class C
{
    public function m()
    {
        $f = function () {
            return debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)[0]['function'];
        };

        return $f();
    }

    public static function s()
    {
        $f = function () {
            return debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)[0]['function'];
        };

        return $f();
    }
}

echo 'method: ';
var_export((new C())->m());
echo "\n";

echo 'static: ';
var_export(C::s());
echo "\n";

$outer = function () {
    $inner = function () {
        return debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)[0]['function'];
    };

    return $inner();
};
echo 'nested: ';
var_export($outer());
echo "\n";

echo 'print:';
$p = function () {
    ob_start();
    debug_print_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
    $out = ob_get_clean();
    if (preg_match('/\{(?:closure|anonymous)[^}]*\}/', $out, $m)) {
        return $m[0];
    }

    return $out;
};
var_export($p());
echo "\n";
