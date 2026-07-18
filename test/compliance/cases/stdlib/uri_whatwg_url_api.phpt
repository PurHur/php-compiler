--TEST--
Uri\WhatWg\Url getters/withers/equals/toUnicodeString (#20541)
--ENV--
PHP_COMPILER_PROFILE=8.4
--SKIPIF--
<?php
if (!class_exists('Uri\\WhatWg\\Url')) die('skip no Uri\\WhatWg\\Url');
?>
--FILE--
<?php
declare(strict_types=1);

$u = Uri\WhatWg\Url::parse('https://user:pass@example.com:8443/a?q=1#f');
echo 'scheme=', $u->getScheme(), "\n";
echo 'host=', $u->getAsciiHost(), "\n";
echo 'unicode=', $u->getUnicodeHost(), "\n";
echo 'path=', $u->getPath(), "\n";
echo 'query=', $u->getQuery(), "\n";
echo 'frag=', $u->getFragment(), "\n";
echo 'port=', $u->getPort(), "\n";
echo 'user=', $u->getUsername(), "\n";
echo 'pass=', $u->getPassword(), "\n";
echo 'ascii=', $u->toAsciiString(), "\n";
echo 'uni=', $u->toUnicodeString(), "\n";

$u2 = $u->withQuery('z=9');
echo 'q2=', $u2->getQuery(), ' orig=', $u->getQuery(), "\n";
$u3 = $u->withFragment('g');
echo 'f3=', $u3->getFragment(), "\n";

$same = Uri\WhatWg\Url::parse('https://user:pass@example.com:8443/a?q=1#other');
echo 'eq_ex=', $u->equals($same) ? 'Y' : 'N', "\n";
echo 'eq_in=', $u->equals($same, Uri\UriComparisonMode::IncludeFragment) ? 'Y' : 'N', "\n";
?>
--EXPECT--
scheme=https
host=example.com
unicode=example.com
path=/a
query=q=1
frag=f
port=8443
user=user
pass=pass
ascii=https://user:pass@example.com:8443/a?q=1#f
uni=https://user:pass@example.com:8443/a?q=1#f
q2=z=9 orig=q=1
f3=g
eq_ex=Y
eq_in=N
