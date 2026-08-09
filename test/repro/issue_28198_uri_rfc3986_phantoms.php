<?php
// #28198 — Uri\Rfc3986 phantoms absent from php-src PHP-8.5 php_uri.stub.php
declare(strict_types=1);

$r = Uri\Rfc3986\Uri::parse('https://example.com');
echo 'getHostType=', method_exists($r, 'getHostType') ? 'yes' : 'no', "\n";
echo 'getUriType=', method_exists($r, 'getUriType') ? 'yes' : 'no', "\n";
echo 'UriHostType=', enum_exists('Uri\\Rfc3986\\UriHostType') ? 'yes' : 'no', "\n";
echo 'UriType=', enum_exists('Uri\\Rfc3986\\UriType') ? 'yes' : 'no', "\n";
echo 'getHost=', $r->getHost(), "\n";
echo 'equals=', method_exists($r, 'equals') ? 'yes' : 'no', "\n";
echo 'resolve=', method_exists($r, 'resolve') ? 'yes' : 'no', "\n";
