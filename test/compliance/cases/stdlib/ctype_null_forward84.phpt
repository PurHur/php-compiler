--TEST--
stdlib ctype_*(null) E_DEPRECATED+false on 8.4 forward profile (#20611, re-#20252, ext/ctype/ctype.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL);

$fns = [
    'ctype_alnum',
    'ctype_alpha',
    'ctype_cntrl',
    'ctype_digit',
    'ctype_graph',
    'ctype_lower',
    'ctype_print',
    'ctype_punct',
    'ctype_space',
    'ctype_upper',
    'ctype_xdigit',
    'ctype_blank',
];

foreach ($fns as $fn) {
    $seen = [];
    set_error_handler(static function (int $no, string $str) use (&$seen): bool {
        $seen[] = [$no, $str];
        return true;
    });
    try {
        $result = $fn(null);
        $caught = '';
    } catch (Throwable $e) {
        $result = null;
        $caught = get_class($e);
    }
    restore_error_handler();
    $depr = 0;
    $msgOk = 0;
    foreach ($seen as [$no, $str]) {
        if (E_DEPRECATED === $no && str_contains($str, 'will be interpreted as string')) {
            $depr = 1;
            if (str_contains($str, $fn.'(): Argument of type null will be interpreted as string in the future')) {
                $msgOk = 1;
            }
        }
    }
    echo $fn, ':result=', var_export($result, true), ' depr=', $depr, ' msg=', $msgOk, ' err=', $caught, "\n";
}
echo 'ok_string=', (int) ctype_alnum('abc'), "\n";
?>
--EXPECT--
ctype_alnum:result=false depr=1 msg=1 err=
ctype_alpha:result=false depr=1 msg=1 err=
ctype_cntrl:result=false depr=1 msg=1 err=
ctype_digit:result=false depr=1 msg=1 err=
ctype_graph:result=false depr=1 msg=1 err=
ctype_lower:result=false depr=1 msg=1 err=
ctype_print:result=false depr=1 msg=1 err=
ctype_punct:result=false depr=1 msg=1 err=
ctype_space:result=false depr=1 msg=1 err=
ctype_upper:result=false depr=1 msg=1 err=
ctype_xdigit:result=false depr=1 msg=1 err=
ctype_blank:result=false depr=1 msg=1 err=
ok_string=1
