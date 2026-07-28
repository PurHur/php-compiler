<?php
// #24176 — mb_str_pad(null) is DEP+coerce on 8.4 (not TypeError; reverts #19184/#22373)
error_reporting(E_ALL);
$seen = [];
set_error_handler(static function (int $no, string $str) use (&$seen): bool {
    if (E_DEPRECATED === $no) {
        $seen[] = $str;
    }

    return true;
});
$threw = false;
try {
    $out = mb_str_pad(null, 5);
} catch (TypeError $e) {
    $threw = true;
    $msg = $e->getMessage();
}
if ($threw) {
    echo 'bad:TypeError', "\n", $msg, "\n";
} else {
    echo 'ok:soft ', var_export($out, true), ' depr=', (int) (count($seen) >= 1), "\n";
}
