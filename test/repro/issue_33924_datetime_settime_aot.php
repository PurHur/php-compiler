<?php
$d = new DateTime('2020-01-01');
$d->setTime(12, 30, 45);
echo $d->format('H:i:s');
