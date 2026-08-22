<?php
// AOT: SplObjectStorage serialize/unserialize roundtrip (#33876)
function walk(SplObjectStorage $s): void
{
    foreach ($s as $obj) {
        echo '|', get_class($obj), '|', $s[$obj];
    }
}

$s = new SplObjectStorage();
$o = new stdClass();
$s->attach($o, 'info');
$wire = serialize($s);
echo (str_starts_with($wire, 'O:16:"SplObjectStorage":2:{') ? 'bag' : 'bad');
echo (str_contains($wire, 'O:8:"stdClass":0:{}') ? '+std' : '+nostd');
$u = unserialize($wire);
echo '|', $u->count();
walk($u);
echo "\n";
