--TEST--
Uri\WhatWg\Url withers/resolve/UrlValidationError (#20949, phantoms retired #28199)
--ENV--
PHP_COMPILER_PROFILE=8.5
--SKIPIF--
<?php
if (!class_exists('Uri\\WhatWg\\Url')) die('skip no Uri\\WhatWg\\Url');
?>
--FILE--
<?php
declare(strict_types=1);

$u = Uri\WhatWg\Url::parse('https://user:pass@example.com:8443/a/b?q=1#f');
echo 'isSpecialScheme=', method_exists($u, 'isSpecialScheme') ? 'Y' : 'N', "\n";
echo 'getHostType=', method_exists($u, 'getHostType') ? 'Y' : 'N', "\n";
echo 'UrlHostType=', enum_exists('Uri\\WhatWg\\UrlHostType') ? 'Y' : 'N', "\n";
echo 'UrlValidationError=', class_exists('Uri\\WhatWg\\UrlValidationError') ? 'Y' : 'N', "\n";
echo 'UrlValidationErrorType=', enum_exists('Uri\\WhatWg\\UrlValidationErrorType') ? 'Y' : 'N', "\n";

$u2 = $u->withScheme('http')->withHost('example.org')->withPath('/z')->withPort(8080)->withUsername('a')->withPassword('b');
echo 'mut=', $u2->toAsciiString(), "\n";
echo 'orig_host=', $u->getAsciiHost(), "\n";

$rel = $u->resolve('../c?x=1');
echo 'resolve_path=', $rel->getPath(), "\n";
echo 'resolve_q=', $rel->getQuery(), "\n";
echo 'resolve_host=', $rel->getAsciiHost(), "\n";

$sameDir = $u->resolve('c');
echo 'same_dir=', $sameDir->getPath(), "\n";

$abs = $u->resolve('https://other.test/x');
echo 'abs=', $abs->toAsciiString(), "\n";
?>
--EXPECT--
isSpecialScheme=N
getHostType=N
UrlHostType=N
UrlValidationError=Y
UrlValidationErrorType=Y
mut=http://a:b@example.org:8080/z?q=1#f
orig_host=example.com
resolve_path=/c
resolve_q=x=1
resolve_host=example.com
same_dir=/a/c
abs=https://other.test/x
