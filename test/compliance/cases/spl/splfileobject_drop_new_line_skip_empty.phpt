--TEST--
SplFileObject DROP_NEW_LINE and SKIP_EMPTY foreach iteration (#19087, ext/spl/spl_file_object.c)
--FILE--
<?php
$path = sys_get_temp_dir() . '/phpc_spl_drop_phpt_' . getmypid() . '.txt';
$p = $path;
file_put_contents($p, "a\nb\n\n");

$f = new SplFileObject($p);
$f->setFlags(SplFileObject::DROP_NEW_LINE | SplFileObject::SKIP_EMPTY);
$lines = [];
foreach ($f as $line) {
    $lines[] = $line;
}

@unlink($p);

echo implode(',', $lines), "\n";
?>
--EXPECT--
a,b,
