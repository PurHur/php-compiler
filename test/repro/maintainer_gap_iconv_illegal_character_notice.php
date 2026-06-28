<?php

$noticed = false;
set_error_handler(static function (int $errno, string $message) use (&$noticed): bool {
    if (E_NOTICE === $errno && str_contains($message, 'illegal character')) {
        $noticed = true;
    }

    return true;
});

$result = iconv('UTF-8', 'ASCII', "\xC3\xBC");
if (false !== $result) {
    echo "fail: iconv() should return false for illegal UTF-8→ASCII conversion\n";
    exit(1);
}
if (!$noticed) {
    echo "fail: iconv() illegal character conversion did not emit E_NOTICE\n";
    exit(1);
}
echo "ok\n";
