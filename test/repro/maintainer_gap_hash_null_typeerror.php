<?php
declare(strict_types=1);

$ok = true;
foreach ([
    'md5' => fn () => md5(null),
    'sha1' => fn () => sha1(null),
    'crc32' => fn () => crc32(null),
] as $name => $call) {
    try {
        $call();
        fwrite(STDERR, "fail: {$name}(null) no TypeError\n");
        $ok = false;
    } catch (\TypeError $e) {
        echo $name, ': ', $e->getMessage(), "\n";
    } catch (\Throwable $e) {
        fwrite(STDERR, "fail: {$name}(null) got " . $e::class . "\n");
        $ok = false;
    }
}

exit($ok ? 0 : 1);
