<?php
// #28199 — Uri\WhatWg\Url phantoms absent from php-src PHP-8.5 php_uri.stub.php
declare(strict_types=1);

$u = Uri\WhatWg\Url::parse('https://example.com');
echo 'isSpecialScheme=', method_exists($u, 'isSpecialScheme') ? 'yes' : 'no', "\n";
echo 'getHostType=', method_exists($u, 'getHostType') ? 'yes' : 'no', "\n";
echo 'UrlHostType=', enum_exists('Uri\\WhatWg\\UrlHostType') ? 'yes' : 'no', "\n";
echo 'getAsciiHost=', $u->getAsciiHost(), "\n";
echo 'getUnicodeHost=', $u->getUnicodeHost(), "\n";
echo 'resolve=', method_exists($u, 'resolve') ? 'yes' : 'no', "\n";
