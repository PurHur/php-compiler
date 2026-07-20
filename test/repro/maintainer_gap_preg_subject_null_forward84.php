<?php
// #21318 — PCRE null $subject soft DEP+coerce under PROFILE=8.4 (siblings of #21198)
set_error_handler(static function (int $no, string $msg): bool {
    if (E_DEPRECATED === $no) {
        echo "DEP\n";

        return true;
    }

    return false;
});
$cb = static function ($m) {
    return 'x';
};
$patterns = ['/x/' => $cb];
foreach ([
    'preg_split' => static function () {
        return preg_split('/x/', null);
    },
    'preg_match_all' => static function () {
        return preg_match_all('/x/', null);
    },
    'preg_replace_callback' => static function () use ($cb) {
        return preg_replace_callback('/x/', $cb, null);
    },
    'preg_replace_callback_array' => static function () use ($patterns) {
        return preg_replace_callback_array($patterns, null);
    },
] as $label => $fn) {
    try {
        $r = $fn();
        echo $label, ':OK ', var_export($r, true), "\n";
    } catch (Throwable $e) {
        echo $label, ':', get_class($e), ' ', $e->getMessage(), "\n";
    }
}
