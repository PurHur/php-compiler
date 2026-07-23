--TEST--
Stdlib: get_mangled_object_vars() on DateTime* — no __dt_* leak (JIT, #22445)
--FILE--
<?php
declare(strict_types=1);

foreach ([
    'DateTime' => new DateTime('2020-01-01'),
    'DateTimeImmutable' => new DateTimeImmutable('2020-01-01'),
    'DateTimeZone' => new DateTimeZone('UTC'),
] as $label => $o) {
    $keys = array_keys(get_mangled_object_vars($o));
    sort($keys);
    echo $label, '=', json_encode($keys), "\n";
}
--EXPECT--
DateTime=[]
DateTimeImmutable=[]
DateTimeZone=[]
