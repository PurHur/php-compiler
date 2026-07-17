--TEST--
stdlib basename()/dirname()/pathinfo() null — coerce on 8.4 forward profile JIT (#19997, ext/standard/filestat.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL & ~E_DEPRECATED);
foreach ([
    'basename' => static fn () => basename(null),
    'dirname' => static fn () => dirname(null),
    'pathinfo' => static fn () => pathinfo(null),
] as $name => $call) {
    try {
        $call();
        echo "{$name}: uncaught\n";
    } catch (TypeError $e) {
        echo $e->getMessage(), "\n";
    }
}
echo var_export(basename(''), true), "\n";
?>
--EXPECT--
basename: uncaught
dirname: uncaught
pathinfo: uncaught
''
