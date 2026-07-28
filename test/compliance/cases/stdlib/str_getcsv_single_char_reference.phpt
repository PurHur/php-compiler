--TEST--
stdlib str_getcsv() multi-byte CSV options still accepted on reference/8.2 profile (#24148)
--FILE--
<?php
foreach ([
    'sep2' => static fn () => str_getcsv('a,b', ',,', '"', '"'),
    'enc2' => static fn () => str_getcsv('a,b', ',', '""', '"'),
    'esc2' => static fn () => str_getcsv('a,b', ',', '"', 'xx'),
] as $name => $call) {
    try {
        $r = $call();
        echo $name, ' OK ', json_encode($r), "\n";
    } catch (Throwable $e) {
        echo $name, ' ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
--EXPECT--
sep2 OK ["a","b"]
enc2 OK ["a","b"]
esc2 OK ["a","b"]
