<?php
// Repro for #20614 — Uri\Rfc3986\Uri getters/withers
declare(strict_types=1);

$u = Uri\Rfc3986\Uri::parse('https://user:pass@example.com:8080/a/b?x=1#frag');
foreach (['getPort', 'getQuery', 'getFragment', 'getUserInfo', 'getUsername', 'getPassword', 'getRawQuery', 'withQuery', 'withPort', 'withFragment'] as $m) {
    echo $m, '=', method_exists($u, $m) ? '1' : '0', PHP_EOL;
}
echo 'port=', $u->getPort(), PHP_EOL;
echo 'query=', $u->getQuery(), PHP_EOL;
echo 'frag=', $u->getFragment(), PHP_EOL;
echo 'ui=', $u->getUserInfo(), PHP_EOL;
$u2 = $u->withQuery('z=9');
echo 'with=', $u2->getQuery(), ' orig=', $u->getQuery(), PHP_EOL;
