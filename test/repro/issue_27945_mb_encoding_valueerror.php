<?php

declare(strict_types=1);

/**
 * #27945 — invalid encoding must be ValueError (php-src ext/mbstring), not LogicException.
 */
$cases = [
    'mb_substr' => static fn () => mb_substr('a', 0, 1, 'nope'),
    'mb_strpos' => static fn () => mb_strpos('a', 'a', 0, 'nope'),
    'mb_str_split' => static fn () => mb_str_split('a', 1, 'nope'),
    'mb_strcut' => static fn () => mb_strcut('a', 0, 1, 'nope'),
    'mb_strwidth' => static fn () => mb_strwidth('a', 'nope'),
    'mb_strimwidth' => static fn () => mb_strimwidth('a', 0, 1, '', 'nope'),
    'mb_stripos' => static fn () => mb_stripos('a', 'a', 0, 'nope'),
    'mb_strrpos' => static fn () => mb_strrpos('a', 'a', 0, 'nope'),
    'mb_strripos' => static fn () => mb_strripos('a', 'a', 0, 'nope'),
    'mb_strstr' => static fn () => mb_strstr('a', 'a', false, 'nope'),
    'mb_stristr' => static fn () => mb_stristr('a', 'a', false, 'nope'),
    'mb_strrchr' => static fn () => mb_strrchr('a', 'a', false, 'nope'),
    'mb_strrichr' => static fn () => mb_strrichr('a', 'a', false, 'nope'),
];

if (\function_exists('mb_str_pad')) {
    $cases['mb_str_pad'] = static fn () => mb_str_pad('a', 3, ' ', STR_PAD_RIGHT, 'nope');
}

foreach ($cases as $name => $fn) {
    try {
        $fn();
        echo $name, " ok\n";
    } catch (Throwable $e) {
        echo $name, ' ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}

echo mb_substr('hello', 0, 2, 'utf8'), "\n";
echo mb_strpos('hello', 'l', 0, 'UTF-8'), "\n";
