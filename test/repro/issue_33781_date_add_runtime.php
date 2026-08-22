<?php
$i = new DateInterval('P1M');
$d = new DateTime('2020-01-15');
date_add($d, $i);
echo $d->format('Y-m-d'), "\n";
