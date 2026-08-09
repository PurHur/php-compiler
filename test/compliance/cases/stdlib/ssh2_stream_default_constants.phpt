--TEST--
stdlib SSH2_STREAM_* + SSH2_DEFAULT_* constants when ssh2 advertised (#28093)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);
echo extension_loaded('ssh2') ? "ext=1\n" : "ext=0\n";
foreach ([
    'SSH2_STREAM_STDIO', 'SSH2_STREAM_STDERR',
    'SSH2_DEFAULT_TERMINAL', 'SSH2_DEFAULT_TERM_WIDTH',
    'SSH2_DEFAULT_TERM_HEIGHT', 'SSH2_DEFAULT_TERM_UNIT',
] as $c) {
    echo $c, '=', defined($c) ? var_export(constant($c), true) : 'MISSING', "\n";
}
echo function_exists('ssh2_fetch_stream') ? "fetch=1\n" : "fetch=0\n";
?>
--EXPECT--
ext=1
SSH2_STREAM_STDIO=0
SSH2_STREAM_STDERR=1
SSH2_DEFAULT_TERMINAL='vanilla'
SSH2_DEFAULT_TERM_WIDTH=80
SSH2_DEFAULT_TERM_HEIGHT=25
SSH2_DEFAULT_TERM_UNIT=0
fetch=1
