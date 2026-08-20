<?php
/** Repro #32691 — thin AOT DateTime::format / getTimestamp must not SIGSEGV. */
$d = new DateTime('2020-01-15', new DateTimeZone('UTC'));
echo $d->format('Y-m-d'), "\n";
echo $d->getTimestamp(), "\n";
