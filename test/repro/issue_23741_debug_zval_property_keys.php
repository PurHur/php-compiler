<?php
// Issue #23741 — debug_zval_dump() private/protected property key presentation
class O {
    public $a = 1;
    private $b = 2;
    protected $c = 3;
}
ob_start();
debug_zval_dump(new O());
$out = ob_get_clean();
assert(str_contains($out, '["b":"O":private]=>'), 'private key: '.$out);
assert(str_contains($out, '["c":protected]=>'), 'protected key: '.$out);
assert(!str_contains($out, "\0"), 'mangled NUL key: '.$out);
echo "OK\n";
