--TEST--
Uri\Rfc3986\Uri __construct/__serialize/__debugInfo (#21468)
--ENV--
PHP_COMPILER_PROFILE=8.4
--SKIPIF--
<?php
if (!class_exists('Uri\\Rfc3986\\Uri')) die('skip no Uri\\Rfc3986\\Uri');
?>
--FILE--
<?php
declare(strict_types=1);

$u = new Uri\Rfc3986\Uri('https://user:pass@example.com:8443/a/b?q=1#f');
echo 'host=', $u->getHost(), "\n";
echo 'path=', $u->getPath(), "\n";

$u2 = unserialize(serialize($u));
echo 'roundtrip=', $u2->toString(), "\n";
echo 'eq=', $u->equals($u2, Uri\UriComparisonMode::IncludeFragment) ? 'Y' : 'N', "\n";

$info = $u->__debugInfo();
echo 'dbg=', $info['scheme'], '|', $info['username'], '|', $info['host'], '|', $info['path'], "\n";

try {
    new Uri\Rfc3986\Uri('://bad');
    echo "no_throw\n";
} catch (Uri\InvalidUriException $e) {
    echo "bad=Y\n";
}

$w = new Uri\WhatWg\Url('https://example.org/foo');
echo 'whatwg=', unserialize(serialize($w))->getAsciiHost(), "\n";
?>
--EXPECT--
host=example.com
path=/a/b
roundtrip=https://user:pass@example.com:8443/a/b?q=1#f
eq=Y
dbg=https|user|example.com|/a/b
bad=Y
whatwg=example.org
