<?php
$d = new DateTime('2020-01-15');
echo 'before=', $d->getTimestamp(), "\n";
$d->add(new DateInterval('P1M'));
echo 'after_ts=', $d->getTimestamp(), "\n";
echo 'after_fmt=', $d->format('Y-m-d'), "\n";
