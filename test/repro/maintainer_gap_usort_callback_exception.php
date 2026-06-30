<?php

declare(strict_types=1);

$a = [1, 2];
$out = '';
try {
    usort($a, static function () use (&$out): void {
        $out = 'threw';
        throw new Exception('boom');
    });
    $out .= ':after';
} catch (Exception $e) {
    $out .= ':caught';
}
if ($out !== 'threw:caught') {
    fwrite(STDERR, "fail: expected threw:caught, got {$out}\n");
    exit(1);
}
echo "ok\n";
