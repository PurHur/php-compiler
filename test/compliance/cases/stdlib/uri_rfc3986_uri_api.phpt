--TEST--
Uri\Rfc3986\Uri getters/withers/toRawString (#20614)
--ENV--
PHP_COMPILER_PROFILE=8.4
--SKIPIF--
<?php
if (!class_exists('Uri\\Rfc3986\\Uri')) die('skip no Uri\\Rfc3986\\Uri');
?>
--FILE--
<?php
declare(strict_types=1);

$u = Uri\Rfc3986\Uri::parse('https://user:pass@example.com:8080/a/b?x=1#frag');
echo 'scheme=', $u->getScheme(), "\n";
echo 'host=', $u->getHost(), "\n";
echo 'path=', $u->getPath(), "\n";
echo 'query=', $u->getQuery(), "\n";
echo 'rawq=', $u->getRawQuery(), "\n";
echo 'frag=', $u->getFragment(), "\n";
echo 'port=', $u->getPort(), "\n";
echo 'ui=', $u->getUserInfo(), "\n";
echo 'user=', $u->getUsername(), "\n";
echo 'pass=', $u->getPassword(), "\n";
echo 'str=', $u->toString(), "\n";
echo 'raw=', $u->toRawString(), "\n";

$u2 = $u->withQuery('z=9');
echo 'q2=', $u2->getQuery(), ' orig=', $u->getQuery(), "\n";
$u3 = $u->withFragment('g');
echo 'f3=', $u3->getFragment(), "\n";
$u4 = $u->withPort(9000);
echo 'p4=', $u4->getPort(), "\n";
$u5 = $u->withPath('/x');
echo 'path5=', $u5->getPath(), "\n";
$u6 = $u->withHost('other.test');
echo 'host6=', $u6->getHost(), "\n";
$u7 = $u->withScheme('http');
echo 'sch7=', $u7->getScheme(), "\n";
$u8 = $u->withUserInfo('a:b');
echo 'ui8=', $u8->getUserInfo(), ' user=', $u8->getUsername(), ' pass=', $u8->getPassword(), "\n";
?>
--EXPECT--
scheme=https
host=example.com
path=/a/b
query=x=1
rawq=x=1
frag=frag
port=8080
ui=user:pass
user=user
pass=pass
str=https://user:pass@example.com:8080/a/b?x=1#frag
raw=https://user:pass@example.com:8080/a/b?x=1#frag
q2=z=9 orig=x=1
f3=g
p4=9000
path5=/x
host6=other.test
sch7=http
ui8=a:b user=a pass=b
