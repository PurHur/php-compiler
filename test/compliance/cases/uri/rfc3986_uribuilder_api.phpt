--TEST--
Uri\Rfc3986\UriBuilder + getUriType/getHostType/resolve/equals (#20950)
--ENV--
PHP_COMPILER_PROFILE=8.4
--SKIPIF--
<?php
if (!class_exists('Uri\\Rfc3986\\Uri')) die('skip no Uri\\Rfc3986\\Uri');
?>
--FILE--
<?php
declare(strict_types=1);

echo 'UriBuilder=', class_exists('Uri\\Rfc3986\\UriBuilder') ? 'Y' : 'N', "\n";
$u = Uri\Rfc3986\Uri::parse('https://example.com/a/b?q=1#f');
echo 'uriType=', $u->getUriType()->name, "\n";
echo 'hostType=', $u->getHostType()->name, "\n";

$same = Uri\Rfc3986\Uri::parse('https://example.com/a/b?q=1#other');
echo 'eq_ex=', $u->equals($same) ? 'Y' : 'N', "\n";
echo 'eq_in=', $u->equals($same, Uri\UriComparisonMode::IncludeFragment) ? 'Y' : 'N', "\n";

$rel = $u->resolve('c');
echo 'resolve=', $rel->getPath(), "\n";

$b = new Uri\Rfc3986\UriBuilder();
$built = $b->setScheme('https')->setHost('built.test')->setPath('/x')->setQuery('a=1')->build();
echo 'built=', $built->toString(), "\n";
echo 'builtType=', $built->getUriType()->name, "\n";

$b2 = (new Uri\Rfc3986\UriBuilder())->setPath('/only')->reset()->setScheme('http')->setHost('r')->setPath('/');
echo 'reset=', $b2->build()->toString(), "\n";
?>
--EXPECT--
UriBuilder=Y
uriType=Uri
hostType=RegisteredName
eq_ex=Y
eq_in=N
resolve=/a/c
built=https://built.test/x?a=1
builtType=Uri
reset=http://r/
