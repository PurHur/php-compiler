--TEST--
mbstring mb_ucfirst()/mb_lcfirst() null $string — DEP+coerce on 8.4 (#24176, reverts #19433)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL);
$seen = [];
set_error_handler(static function (int $no, string $str) use (&$seen): bool {
    if (E_DEPRECATED === $no) {
        $seen[] = $str;
    }
    return true;
});
foreach (['mb_ucfirst', 'mb_lcfirst'] as $f) {
    try {
        echo $f, '=', var_export($f(null), true), "\n";
    } catch (TypeError $e) {
        echo $f, ": TypeError\n";
    }
}
restore_error_handler();
echo mb_ucfirst('über'), "\n";
echo 'depr=', (int) (count($seen) >= 2), "\n";
?>
--EXPECT--
mb_ucfirst=''
mb_lcfirst=''
Über
depr=1
