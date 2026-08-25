<?php
// #34599 / peer #34594 — AOT runtime unserialize DateTime*/DateTimeZone Zend wire
declare(strict_types=1);

$dt = new DateTime('2020-01-02', new DateTimeZone('UTC'));
$s = serialize($dt);
$u = unserialize($s);
echo 'DT:', $u->format('Y-m-d'), "\n";

$dti = new DateTimeImmutable('2020-01-02', new DateTimeZone('UTC'));
$s = serialize($dti);
$u = unserialize($s);
echo 'DTI:', $u->format('Y-m-d'), "\n";

$z = new DateTimeZone('Europe/Berlin');
$s = serialize($z);
$u = unserialize($s);
echo 'TZ:', $u->getName(), "\n";

// Folded path (#34576) must stay green.
echo 'FOLD:', unserialize(serialize(new DateTime('2020-01-02', new DateTimeZone('UTC'))))->format('Y-m-d'), "\n";
