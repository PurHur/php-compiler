--TEST--
SPL SplFileObject READ_CSV current()/foreach yields CSV arrays (#19663, ext/spl/spl_directory.c)
--FILE--
<?php
$tmp = sys_get_temp_dir() . '/phpc_sfo_read_csv_' . getmypid() . '.csv';
file_put_contents($tmp, "a,b\n1,2\n");

$o = new SplFileObject($tmp);
$o->setFlags(SplFileObject::READ_CSV);
$rows = [];
foreach ($o as $i => $row) {
    $rows[$i] = $row;
}
echo 'count=', count($rows), "\n";
echo 't0=', gettype($rows[0] ?? null), "\n";
echo 'r0=';
var_export($rows[0] ?? null);
echo "\n";
echo 'r1=';
var_export($rows[1] ?? null);
echo "\n";
if (isset($rows[2])) {
    echo 'r2=';
    var_export($rows[2]);
    echo "\n";
}

$o = new SplFileObject($tmp);
$o->setFlags(SplFileObject::READ_CSV);
$o->rewind();
echo 'current0=';
var_export($o->current());
echo "\n";

// fgetcsv() method still works independently
$o = new SplFileObject($tmp);
echo 'fgetcsv=';
var_export($o->fgetcsv());
echo "\n";

unlink($tmp);
?>
--EXPECT--
count=3
t0=array
r0=array (
  0 => 'a',
  1 => 'b',
)
r1=array (
  0 => '1',
  1 => '2',
)
r2=array (
  0 => NULL,
)
current0=array (
  0 => 'a',
  1 => 'b',
)
fgetcsv=array (
  0 => 'a',
  1 => 'b',
)
