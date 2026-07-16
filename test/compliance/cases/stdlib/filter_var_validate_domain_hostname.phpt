--TEST--
stdlib filter_var() FILTER_VALIDATE_DOMAIN loose vs FILTER_FLAG_HOSTNAME (#19370, ext/filter/logical_filters.c)
--FILE--
<?php
declare(strict_types=1);
foreach (['example.com', '-bad-.com', 'ex_ample.com', 'a.b', 'example..com', '.com'] as $c) {
    $plain = filter_var($c, FILTER_VALIDATE_DOMAIN);
    $host = filter_var($c, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME);
    echo $c, ' plain=', var_export($plain, true), ' host=', var_export($host, true), "\n";
}
--EXPECT--
example.com plain='example.com' host='example.com'
-bad-.com plain='-bad-.com' host=false
ex_ample.com plain='ex_ample.com' host=false
a.b plain='a.b' host='a.b'
example..com plain=false host=false
.com plain=false host=false
