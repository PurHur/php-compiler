--TEST--
stdlib array pointer builtins emit E_DEPRECATED on objects (#23574, ext/standard/array.c)
--FILE--
<?php
error_reporting(E_ALL);
$warnings = [];
set_error_handler(static function (int $no, string $msg) use (&$warnings): bool {
    if (E_DEPRECATED === $no) {
        $warnings[] = $msg;
    }
    return true;
});
$o = (object) ['a' => 1, 'b' => 2];
$v = next($o);
restore_error_handler();
echo 'value=', var_export($v, true), "\n";
echo 'warn=', var_export($warnings[0] ?? null, true), "\n";
foreach (['current', 'prev', 'reset', 'end', 'key', 'pos'] as $fn) {
    $warnings = [];
    set_error_handler(static function (int $no, string $msg) use (&$warnings): bool {
        if (E_DEPRECATED === $no) {
            $warnings[] = $msg;
        }
        return true;
    });
    $obj = (object) ['a' => 1, 'b' => 2];
    $fn($obj);
    restore_error_handler();
    echo $fn, '_warn=', var_export($warnings[0] ?? null, true), "\n";
}
--EXPECT--
value=2
warn='next(): Calling next() on an object is deprecated'
current_warn='current(): Calling current() on an object is deprecated'
prev_warn='prev(): Calling prev() on an object is deprecated'
reset_warn='reset(): Calling reset() on an object is deprecated'
end_warn='end(): Calling end() on an object is deprecated'
key_warn='key(): Calling key() on an object is deprecated'
pos_warn='pos(): Calling pos() on an object is deprecated'
