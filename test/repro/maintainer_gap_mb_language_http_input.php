<?php

declare(strict_types=1);

/**
 * Issue #4636 — mb_language() / mb_http_input() encoding metadata (ext/mbstring/mbstring.c).
 */
if (!function_exists('mb_language')) {
    echo "fail: mb_language missing\n";
    exit(1);
}
if (!function_exists('mb_http_input')) {
    echo "fail: mb_http_input missing\n";
    exit(1);
}

if ('neutral' !== mb_language()) {
    echo "fail: default language\n";
    exit(1);
}
if (true !== mb_language('uni') || 'uni' !== mb_language()) {
    echo "fail: set uni\n";
    exit(1);
}
if (true !== mb_language('Japanese') || 'Japanese' !== mb_language()) {
    echo "fail: set Japanese\n";
    exit(1);
}

mb_internal_encoding('UTF-8');
$s = '  üñîçø∂é  ';
if (11 !== mb_strlen($s)) {
    echo "fail: mb_strlen after internal_encoding\n";
    exit(1);
}

if (false !== mb_http_input()) {
    echo "fail: mb_http_input() no arg\n";
    exit(1);
}
if (false !== mb_http_input('G')) {
    echo "fail: mb_http_input G\n";
    exit(1);
}
if ('UTF-8' !== mb_http_input('L')) {
    echo "fail: mb_http_input L\n";
    exit(1);
}
if (['UTF-8'] !== mb_http_input('I')) {
    echo "fail: mb_http_input I\n";
    exit(1);
}

try {
    mb_language('bogus');
    echo "fail: invalid language uncaught\n";
    exit(1);
} catch (ValueError $e) {
    if (!str_contains($e->getMessage(), 'must be a valid language')) {
        echo "fail: invalid language message\n";
        exit(1);
    }
}

try {
    mb_http_input('X');
    echo "fail: invalid type uncaught\n";
    exit(1);
} catch (ValueError $e) {
    if (!str_contains($e->getMessage(), 'must be one of')) {
        echo "fail: invalid type message\n";
        exit(1);
    }
}

echo "ok\n";
