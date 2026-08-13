--TEST--
stream/file builtins excess argc → ArgumentCountError (#30584)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
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
?>
--EXPECT--
fpassthru() expects exactly 1 argument, 2 given
fflush() expects exactly 1 argument, 2 given
fseek() expects at most 3 arguments, 4 given
ftell() expects exactly 1 argument, 2 given
feof() expects exactly 1 argument, 2 given
fgetc() expects exactly 1 argument, 2 given
rewind() expects exactly 1 argument, 2 given
stream_get_meta_data() expects exactly 1 argument, 2 given
stream_context_create() expects at most 2 arguments, 3 given
stream_context_set_option() expects at most 4 arguments, 5 given
stream_context_get_params() expects exactly 1 argument, 2 given
