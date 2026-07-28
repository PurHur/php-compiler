--TEST--
AOT parse_url() retains empty query/fragment when ?/# present (#24400)
--FILE--
<?php
$q = parse_url('http://x.com?');
echo array_key_exists('query', $q) ? 'qyes' : 'qno', '|', strlen($q['query']), "\n";
$f = parse_url('http://x.com#');
echo array_key_exists('fragment', $f) ? 'fyes' : 'fno', '|', strlen($f['fragment']), "\n";
$eq = parse_url('http://x.com?', PHP_URL_QUERY);
echo is_string($eq) ? 'qstr' : 'qother', '|', strlen($eq), "\n";
$ef = parse_url('http://x.com#', PHP_URL_FRAGMENT);
echo is_string($ef) ? 'fstr' : 'fother', '|', strlen($ef), "\n";
$absent = parse_url('http://x.com', PHP_URL_QUERY);
echo null === $absent ? 'null' : 'notnull', "\n";
--EXPECT--
qyes|0
fyes|0
qstr|0
fstr|0
null
