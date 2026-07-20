<?php

declare(strict_types=1);

$u = new Uri\Rfc3986\Uri('https://user:pass@example.com:8443/a/b?q=1#f');
echo 'host=', $u->getHost(), "\n";
echo 'path=', $u->getPath(), "\n";

$ser = serialize($u);
$u2 = unserialize($ser);
echo 'roundtrip=', $u2->toString(), "\n";
echo 'eq=', $u->equals($u2, Uri\UriComparisonMode::IncludeFragment) ? 'Y' : 'N', "\n";

$info = $u->__debugInfo();
echo 'dbg_host=', $info['host'] ?? 'missing', "\n";
echo 'dbg_user=', $info['username'] ?? 'missing', "\n";

try {
    new Uri\Rfc3986\Uri('://bad');
    echo "fail: construct bad must throw\n";
    exit(1);
} catch (Uri\InvalidUriException $e) {
    echo 'bad=', $e->getMessage(), "\n";
}

$w = new Uri\WhatWg\Url('https://example.org/foo');
echo 'whatwg=', $w->getAsciiHost(), "\n";
$w2 = unserialize(serialize($w));
echo 'whatwg_rt=', $w2->toAsciiString(), "\n";

echo "ok\n";
