--TEST--
SPL SplFileObject::fgetcsv trailing empty row is array(null) (#24290, ext/spl/spl_directory.c)
--FILE--
<?php
$p = sys_get_temp_dir() . '/phpc_sfo_csv_' . getmypid() . '.csv';
file_put_contents($p, "x\n");
$fo = new SplFileObject($p);
for ($i = 0; $i < 3; $i++) {
    echo "i=$i ", var_export($fo->fgetcsv(), true), "\n";
}
@unlink($p);
?>
--EXPECT--
i=0 array (
  0 => 'x',
)
i=1 array (
  0 => NULL,
)
i=2 false
