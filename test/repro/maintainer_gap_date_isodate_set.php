<?php
/** Repro for #20016 — date_isodate_set ISO week date. */
$d = date_create('2000-01-01');
date_isodate_set($d, 2008, 2, 1);
echo $d->format('Y-m-d'), "\n";
