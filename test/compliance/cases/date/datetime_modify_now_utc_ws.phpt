--TEST--
DateTime::modify now/UTC/whitespace succeed without Warning (#31603)
--FILE--
<?php
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $str): bool {
    echo 'W:', $str, "\n";
    return true;
});
foreach (['now', 'UTC', ' ', "\t"] as $m) {
    $d = new DateTime('2020-01-01 12:00:00');
    $r = $d->modify($m);
    echo json_encode($m), ' ret=', false === $r ? 'false' : 'obj',
        ' fmt=', $d->format('Y-m-d H:i:s'), "\n";
}
$i = new DateTimeImmutable('2020-01-01 12:00:00');
$i2 = $i->modify('now');
echo 'imm ret=', false === $i2 ? 'false' : 'obj',
    ' fmt=', false === $i2 ? '-' : $i2->format('Y-m-d H:i:s'), "\n";
?>
--EXPECT--
"now" ret=obj fmt=2020-01-01 12:00:00
"UTC" ret=obj fmt=2020-01-01 12:00:00
" " ret=obj fmt=2020-01-01 12:00:00
"\t" ret=obj fmt=2020-01-01 12:00:00
imm ret=obj fmt=2020-01-01 12:00:00
