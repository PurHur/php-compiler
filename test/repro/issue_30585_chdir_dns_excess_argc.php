<?php
/**
 * chdir/gethostbyname/gethostbynamel excess argc → ArgumentCountError (#30585).
 * php-src: ext/standard/dir.c / dns.c
 */
foreach ([
    'chdir_hi' => static fn () => chdir('.', 'extra'),
    'chdir_lo' => static fn () => chdir(),
    'gethostbyname_hi' => static fn () => gethostbyname('localhost', 'extra'),
    'gethostbyname_lo' => static fn () => gethostbyname(),
    'gethostbynamel_hi' => static fn () => gethostbynamel('localhost', 'extra'),
    'gethostbynamel_lo' => static fn () => gethostbynamel(),
] as $name => $call) {
    try {
        $call();
        echo $name, ":OK\n";
    } catch (ArgumentCountError $e) {
        echo $name, ':ArgumentCountError:', $e->getMessage(), "\n";
    } catch (Throwable $e) {
        echo $name, ':', get_class($e), ':', $e->getMessage(), "\n";
    }
}

$cwd = getcwd();
echo 'ok_chdir:', chdir($cwd) ? '1' : '0', "\n";
$host = gethostbyname('localhost');
echo 'ok_gethostbyname:', (is_string($host) && '' !== $host) ? '1' : '0', "\n";
$list = gethostbynamel('localhost');
echo 'ok_gethostbynamel:', (is_array($list) || false === $list) ? '1' : '0', "\n";
