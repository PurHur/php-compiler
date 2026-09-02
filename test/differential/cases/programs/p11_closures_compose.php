<?php
// #36221 program: closures compose / use-by-ref / pipeline
$log = [];
$make = static function (string $prefix) use (&$log) {
    return static function (string $msg) use ($prefix, &$log): string {
        $line = $prefix . ':' . $msg;
        $log[] = $line;
        return $line;
    };
};
$info = $make('INFO');
$err = $make('ERR');
$pipe = static function (callable ...$fns): callable {
    return static function ($v) use ($fns) {
        foreach ($fns as $fn) {
            $v = $fn($v);
        }
        return $v;
    };
};
$upper = static function (string $s): string { return strtoupper($s); };
$exclaim = static function (string $s): string { return $s . '!'; };
$run = $pipe($upper, $exclaim, $info);
$out1 = $run('hello');
$out2 = $err('boom');
$out = $out1 . '|' . $out2 . '|' . implode(',', $log) . '|n=' . count($log) . "\n";
echo $out;
echo 'checksum=', strlen($out), ':', sprintf('%u', crc32($out)), "\n";
