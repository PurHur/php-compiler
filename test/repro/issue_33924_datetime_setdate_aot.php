<?php
$d = new DateTime('2020-01-01');
$d->setDate(2021, 2, 3);
echo $d->format('Y-m-d');
