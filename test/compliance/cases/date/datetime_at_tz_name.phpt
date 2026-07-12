--TEST--
date: DateTime @unix timestamp getTimezone()->getName() is +00:00 offset not UTC (#18388)
--FILE--
<?php
declare(strict_types=1);
echo (new DateTime('@1609459200'))->getTimezone()->getName(), "\n";
echo (new DateTimeImmutable('@0'))->getTimezone()->getName(), "\n";
echo (new DateTime('@1609459200'))->format('U'), "\n";
--EXPECT--
+00:00
+00:00
1609459200
