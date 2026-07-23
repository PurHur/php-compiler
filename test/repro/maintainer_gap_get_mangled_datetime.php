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

class MyDT22445 extends DateTime
{
    public int $x = 1;
    private string $y = 'hid';
}
$m = get_mangled_object_vars(new MyDT22445('2020-01-01'));
ksort($m);
echo 'MyDT=';
foreach (array_keys($m) as $k) {
    echo json_encode($k), ';';
}
echo "\n";
