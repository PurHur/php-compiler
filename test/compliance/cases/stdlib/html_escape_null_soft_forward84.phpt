--TEST--
stdlib HTML/escape soft-null on 8.4 — htmlspecialchars family (#21180)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
set_error_handler(static function (int $no, string $msg): bool {
    if (E_DEPRECATED === $no) {
        echo "DEP\n";
        return true;
    }
    return false;
});
foreach (['htmlspecialchars','htmlentities','addslashes','stripslashes','nl2br','quotemeta'] as $f) {
    try {
        $r = $f(null);
        echo $f, ' OK ', var_export($r, true), "\n";
    } catch (Throwable $e) {
        echo $f, ' ', get_class($e), "\n";
    }
}
?>
--EXPECT--
DEP
htmlspecialchars OK ''
DEP
htmlentities OK ''
DEP
addslashes OK ''
DEP
stripslashes OK ''
DEP
nl2br OK ''
DEP
quotemeta OK ''
