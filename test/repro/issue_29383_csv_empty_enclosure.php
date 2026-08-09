<?php
/** Repro #29383 — empty $enclosure ValueError before omitted-$escape DEP (PROFILE≥8.4). */
error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message): bool {
    echo "DEP:", $message, "\n";

    return true;
});

foreach ([
    'str_getcsv' => static function (): void {
        str_getcsv('a,b', ',', '');
    },
    'fgetcsv' => static function (): void {
        $fp = fopen('php://memory', 'r+');
        fwrite($fp, "a,b\n");
        rewind($fp);
        try {
            fgetcsv($fp, null, ',', '');
        } finally {
            fclose($fp);
        }
    },
    'fputcsv' => static function (): void {
        $fp = fopen('php://memory', 'r+');
        try {
            fputcsv($fp, ['a', 'b'], ',', '');
        } finally {
            fclose($fp);
        }
    },
] as $name => $fn) {
    echo "== $name ==\n";
    try {
        $fn();
        echo "no error\n";
    } catch (Throwable $e) {
        echo get_class($e), ':', $e->getMessage(), "\n";
    }
}

// Still emit escape DEP on otherwise-valid omitted-escape call (#21174).
echo "== valid_omit_escape ==\n";
$r = str_getcsv('a,b', ',', '"');
echo is_array($r) ? 'ok' : 'bad', "\n";
