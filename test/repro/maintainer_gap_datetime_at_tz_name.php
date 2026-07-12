<?php

declare(strict_types=1);

echo (new DateTime('@1609459200'))->getTimezone()->getName(), "\n";
echo (new DateTimeImmutable('@0'))->getTimezone()->getName(), "\n";
echo (new DateTime('@1609459200'))->format('U'), "\n";
