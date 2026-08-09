--TEST--
Uri\Rfc3986\UriBuilder + resolve/equals; phantoms retired (#20950, #28198)
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

echo 'UriBuilder=', class_exists('Uri\\Rfc3986\\UriBuilder') ? 'Y' : 'N', "\n";
$u = Uri\Rfc3986\Uri::parse('https://example.com/a/b?q=1#f');
echo 'getUriType=', method_exists($u, 'getUriType') ? 'Y' : 'N', "\n";
echo 'getHostType=', method_exists($u, 'getHostType') ? 'Y' : 'N', "\n";
echo 'UriType=', enum_exists('Uri\\Rfc3986\\UriType') ? 'Y' : 'N', "\n";
echo 'UriHostType=', enum_exists('Uri\\Rfc3986\\UriHostType') ? 'Y' : 'N', "\n";
echo 'getHost=', $u->getHost(), "\n";

$same = Uri\Rfc3986\Uri::parse('https://example.com/a/b?q=1#other');
echo 'eq_ex=', $u->equals($same) ? 'Y' : 'N', "\n";
echo 'eq_in=', $u->equals($same, Uri\UriComparisonMode::IncludeFragment) ? 'Y' : 'N', "\n";

$rel = $u->resolve('c');
echo 'resolve=', $rel->getPath(), "\n";

$b = new Uri\Rfc3986\UriBuilder();
$built = $b->setScheme('https')->setHost('built.test')->setPath('/x')->setQuery('a=1')->build();
echo 'built=', $built->toString(), "\n";

$b2 = (new Uri\Rfc3986\UriBuilder())->setPath('/only')->reset()->setScheme('http')->setHost('r')->setPath('/');
echo 'reset=', $b2->build()->toString(), "\n";
?>
--EXPECT--
UriBuilder=Y
getUriType=N
getHostType=N
UriType=N
UriHostType=N
getHost=example.com
eq_ex=Y
eq_in=N
resolve=/a/c
built=https://built.test/x?a=1
reset=http://r/
