<?php
echo (new DateTime('2020-01-15 12:00'))->format('Y-m-d H:i:s'), "\n";
echo (new DateTime('2020-01-15T12:00'))->format('Y-m-d H:i:s'), "\n";
echo (new DateTime('2020-01-15T12:00:00'))->format('Y-m-d H:i:s'), "\n";
echo (new DateTime('2020-01-15 12:00:30'))->format('Y-m-d H:i:s'), "\n";
echo (new DateTimeImmutable('2020-01-15T12:00'))->format('Y-m-d H:i:s'), "\n";
echo date_create('2020-01-15 12:00')->format('Y-m-d H:i:s'), "\n";
