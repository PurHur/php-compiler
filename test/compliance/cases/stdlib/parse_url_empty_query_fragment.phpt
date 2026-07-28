--TEST--
stdlib parse_url() retains empty query/fragment when ?/# present (#24400, php-src url.c)
--FILE--
<?php
foreach (['http://x.com?', 'http://x.com#', 'http://x.com?#'] as $u) {
    $parts = parse_url($u);
    echo $u, ' query_set=', array_key_exists('query', $parts) ? 'yes' : 'no';
    echo ' query=', var_export($parts['query'] ?? null, true);
    echo ' frag_set=', array_key_exists('fragment', $parts) ? 'yes' : 'no';
    echo ' frag=', var_export($parts['fragment'] ?? null, true), "\n";
}
echo 'PHP_URL_QUERY empty=', var_export(parse_url('http://x.com?', PHP_URL_QUERY), true), "\n";
echo 'PHP_URL_FRAGMENT empty=', var_export(parse_url('http://x.com#', PHP_URL_FRAGMENT), true), "\n";
echo 'PHP_URL_QUERY absent=', var_export(parse_url('http://x.com', PHP_URL_QUERY), true), "\n";
echo 'nonempty query=', var_export(parse_url('http://x.com?a=1', PHP_URL_QUERY), true), "\n";
echo 'nonempty frag=', var_export(parse_url('http://x.com#f', PHP_URL_FRAGMENT), true), "\n";
--EXPECT--
http://x.com? query_set=yes query='' frag_set=no frag=NULL
http://x.com# query_set=no query=NULL frag_set=yes frag=''
http://x.com?# query_set=yes query='' frag_set=yes frag=''
PHP_URL_QUERY empty=''
PHP_URL_FRAGMENT empty=''
PHP_URL_QUERY absent=NULL
nonempty query='a=1'
nonempty frag='f'
