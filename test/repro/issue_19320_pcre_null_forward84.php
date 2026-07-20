<?php
/**
 * Issue #19320 / #21198 — preg_quote/match_all/split null TypeError on 8.4;
 * preg_match subject is soft DEP+coerce (#21198).
 */
set_error_handler(static function (int $no, string $msg): bool {
    if (E_DEPRECATED === $no) {
        echo "DEP ";

        return true;
    }

    return false;
});
foreach ([
    'preg_quote' => static fn () => preg_quote(null),
    'preg_match' => static fn () => preg_match('/./', null),
    'preg_match_all' => static fn () => preg_match_all('/./', null),
    'preg_split' => static fn () => preg_split('/./', null),
] as $name => $fn) {
    try {
        $fn();
        echo $name, " COERCE\n";
    } catch (Throwable $e) {
        echo $name, ' ', get_class($e), "\n";
    }
}
