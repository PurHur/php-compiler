--TEST--
AOT: DateTime::format / getTimestamp execute (not compile-only) after new (#32691, re-#27192)
--FILE--
<?php
$d = new DateTime('2020-01-15', new DateTimeZone('UTC'));
echo $d->format('Y-m-d'), "\n";
echo $d->getTimestamp(), "\n";
$d2 = new DateTime('2020-01-15');
echo $d2->format('Y-m-d'), "\n";
$imm = new DateTimeImmutable('2024-06-05 08:00:00', new DateTimeZone('UTC'));
echo $imm->format('Y-m-d H:i:s'), "\n";
?>
--EXPECT--
2020-01-15
1579046400
2020-01-15
2024-06-05 08:00:00
--EXPECT_EXIT--
0
