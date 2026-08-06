--TEST--
stdlib case/HTML/type builtins wrong argc — ArgumentCountError not LogicException (#28317)
--FILE--
<?php
$cases = [
    'strtolower' => static function () { strtolower('A', 'x'); },
    'strtoupper' => static function () { strtoupper('a', 'x'); },
    'ucfirst' => static function () { ucfirst('a', 'x'); },
    'lcfirst' => static function () { lcfirst('A', 'x'); },
    'ucwords' => static function () { ucwords('a b', ' ', 'x'); },
    'strrev' => static function () { strrev('ab', 'x'); },
    'quotemeta' => static function () { quotemeta('a.', 'x'); },
    'htmlentities' => static function () { htmlentities('a', ENT_QUOTES, 'UTF-8', true, 'x'); },
    'html_entity_decode' => static function () { html_entity_decode('&amp;', ENT_QUOTES, 'UTF-8', 'x'); },
    'get_debug_type' => static function () { get_debug_type(1, 'x'); },
    'is_iterable' => static function () { is_iterable([], 'x'); },
];
foreach ($cases as $name => $fn) {
    try {
        $fn();
        echo $name, " ran\n";
    } catch (Throwable $e) {
        echo $name, ' ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
strtolower ArgumentCountError: strtolower() expects exactly 1 argument, 2 given
strtoupper ArgumentCountError: strtoupper() expects exactly 1 argument, 2 given
ucfirst ArgumentCountError: ucfirst() expects exactly 1 argument, 2 given
lcfirst ArgumentCountError: lcfirst() expects exactly 1 argument, 2 given
ucwords ArgumentCountError: ucwords() expects at most 2 arguments, 3 given
strrev ArgumentCountError: strrev() expects exactly 1 argument, 2 given
quotemeta ArgumentCountError: quotemeta() expects exactly 1 argument, 2 given
htmlentities ArgumentCountError: htmlentities() expects at most 4 arguments, 5 given
html_entity_decode ArgumentCountError: html_entity_decode() expects at most 3 arguments, 4 given
get_debug_type ArgumentCountError: get_debug_type() expects exactly 1 argument, 2 given
is_iterable ArgumentCountError: is_iterable() expects exactly 1 argument, 2 given
