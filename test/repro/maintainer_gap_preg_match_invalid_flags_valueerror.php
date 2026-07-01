<?php

declare(strict_types=1);

$fail = 0;

$cases = [
    static function (): void {
        preg_match('/(a)(b)/', 'ab', $m, 99999);
    },
    static function (): void {
        preg_match_all('/a/', 'aaa', $m, 99999);
    },
    static function (): void {
        count_chars('hello', 999);
    },
];

foreach ($cases as $i => $fn) {
    try {
        $fn();
        fwrite(STDERR, "fail: case $i no exception\n");
        $fail = 1;
    } catch (\ValueError $e) {
        continue;
    } catch (\Throwable $e) {
        fwrite(STDERR, 'fail: case '.$i.' '.get_class($e).': '.$e->getMessage()."\n");
        $fail = 1;
    }
}

exit($fail);
