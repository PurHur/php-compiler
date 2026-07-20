<?php
// Repro #21198 — preg_match/preg_replace null $subject DEP+coerce under PROFILE=8.4
set_error_handler(static function (int $no, string $msg): bool {
    if (E_DEPRECATED === $no) {
        echo "DEP\n";

        return true;
    }

    return false;
});
foreach ([
    ['preg_match', ['/a/', null]],
    ['preg_replace', ['/a/', 'b', null]],
] as [$f, $a]) {
    try {
        $r = $f(...$a);
        echo $f, ' OK ', var_export($r, true), PHP_EOL;
    } catch (Throwable $e) {
        echo $f, ' ', get_class($e), PHP_EOL;
    }
}
