<?php
$f = fopen('php://memory', 'r+');
fwrite($f, 'x');
rewind($f);
$c = stream_context_create();
foreach ([
    static function () use ($f) {
        fpassthru($f, 'extra');
    },
    static function () use ($f) {
        fflush($f, 'extra');
    },
    static function () use ($f) {
        fseek($f, 0, SEEK_SET, 'extra');
    },
    static function () use ($f) {
        ftell($f, 'extra');
    },
    static function () use ($f) {
        feof($f, 'extra');
    },
    static function () use ($f) {
        fgetc($f, 'extra');
    },
    static function () use ($f) {
        rewind($f, 'extra');
    },
    static function () use ($f) {
        stream_get_meta_data($f, 'extra');
    },
    static function () {
        stream_context_create([], [], 'extra');
    },
    static function () use ($c) {
        stream_context_set_option($c, 'http', 'method', 'GET', 'extra');
    },
    static function () use ($c) {
        stream_context_get_params($c, 'extra');
    },
] as $fn) {
    try {
        $fn();
        echo "NO_THROW\n";
    } catch (ArgumentCountError $e) {
        echo $e->getMessage(), "\n";
    } catch (Throwable $e) {
        echo get_class($e), ':', $e->getMessage(), "\n";
    }
}
