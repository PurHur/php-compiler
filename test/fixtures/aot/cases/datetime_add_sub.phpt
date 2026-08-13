--TEST--
AOT: DateTime::add/sub + DateTimeImmutable::add/sub P1D (#30760)
--FILE--
<?php
$d = new DateTime('2020-01-15', new DateTimeZone('UTC'));
$d->add(new DateInterval('P1D'));
echo $d->format('Y-m-d'), "\n";
$d->sub(new DateInterval('P1D'));
echo $d->format('Y-m-d'), "\n";
$imm = new DateTimeImmutable('2020-01-15', new DateTimeZone('UTC'));
$imm2 = $imm->add(new DateInterval('P1D'));
echo $imm->format('Y-m-d'), ',', $imm2->format('Y-m-d'), "\n";
$imm3 = $imm2->sub(new DateInterval('P1D'));
echo $imm2->format('Y-m-d'), ',', $imm3->format('Y-m-d'), "\n";
?>
--EXPECT--
2020-01-16
2020-01-15
2020-01-15,2020-01-16
2020-01-16,2020-01-15
