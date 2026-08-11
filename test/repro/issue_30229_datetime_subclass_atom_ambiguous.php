<?php

/**
 * #30229 — DateTime subclass must not fatal on DateTimeInterface format constants.
 * Zend: prints 2020-01-01. Pre-fix VM: ambiguous DateTime::ATOM vs DateTimeInterface::ATOM.
 */
class MyDate extends DateTime
{
}

$d = DateTime::createFromInterface(new MyDate('2020-01-01'));
echo $d->format('Y-m-d'), "\n";
