--TEST--
Uri WhatWg/Rfc3986 ASCII host lowercasing (#28197)
--ENV--
PHP_COMPILER_PROFILE=8.5
--SKIPIF--
<?php
if (!\PHPCompiler\CompilerVersion::supportsUri()) {
    die('skip ext/uri withheld on reference profile (#17830)');
}
?>
--FILE--
<?php
declare(strict_types=1);

$a = Uri\WhatWg\Url::parse('https://EXAMPLE.com/a');
$b = Uri\WhatWg\Url::parse('https://example.com/a');
echo 'asciiHost=', $a->getAsciiHost(), "\n";
echo 'asciiStr=', $a->toAsciiString(), "\n";
echo 'equals=', $a->equals($b) ? 'eq' : 'ne', "\n";
$r = Uri\Rfc3986\Uri::parse('https://EXAMPLE.com/a');
echo 'getHost=', $r->getHost(), "\n";
echo 'rawHost=', $r->getRawHost(), "\n";
$w = $a->withHost('FOO.Bar');
echo 'withHost=', $w->getAsciiHost(), "\n";
?>
--EXPECT--
asciiHost=example.com
asciiStr=https://example.com/a
equals=eq
getHost=example.com
rawHost=EXAMPLE.com
withHost=foo.bar
--CREDITS--
PurHur/php-compiler #28197
