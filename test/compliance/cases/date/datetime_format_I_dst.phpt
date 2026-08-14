--TEST--
DateTime/DateTimeImmutable format('I') DST flag (#31048)
--FILE--
<?php
date_default_timezone_set('America/New_York');
$summer = new DateTime('2024-07-15 12:00:00');
$winter = new DateTime('2024-01-15 12:00:00');
echo $summer->format('I T O'), "\n";
echo $winter->format('I T O'), "\n";
$imm = new DateTimeImmutable('2024-07-15 12:00:00');
echo $imm->format('I T'), "\n";
?>
--EXPECT--
1 EDT -0400
0 EST -0500
1 EDT
