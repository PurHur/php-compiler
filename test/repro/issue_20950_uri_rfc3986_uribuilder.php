<?php
// Repro for #20950 — Uri\Rfc3986\UriBuilder + resolve/equals (phantoms retired in #28198)
declare(strict_types=1);

echo 'UriBuilder=', class_exists('Uri\\Rfc3986\\UriBuilder') ? 'yes' : 'no', PHP_EOL;
$u = Uri\Rfc3986\Uri::parse('https://example.com/path?q=1#f');
foreach (['getUriType', 'getHostType', 'resolve', 'equals', 'getHost', 'toString'] as $m) {
    echo $m, '=', method_exists($u, $m) ? 'yes' : 'no', PHP_EOL;
}

$same = Uri\Rfc3986\Uri::parse('https://example.com/path?q=1#other');
echo 'eq_ex=', $u->equals($same) ? 'Y' : 'N', PHP_EOL;
echo 'eq_in=', $u->equals($same, Uri\UriComparisonMode::IncludeFragment) ? 'Y' : 'N', PHP_EOL;

$rel = $u->resolve('c');
echo 'resolve=', $rel->getPath(), PHP_EOL;

$b = new Uri\Rfc3986\UriBuilder();
$built = $b->setScheme('https')->setHost('built.test')->setPath('/x')->setQuery('a=1')->build();
echo 'built=', $built->toString(), PHP_EOL;
