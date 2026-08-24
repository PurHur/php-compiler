<?php
// #34476 — AOT DateTime::format('U.u') must keep microseconds (php_format_date U/u).
// NestedJIT formatStateArgv 'U.u' special-case miscompiles under thin PROFILE=8.4 AOT.
echo DateTime::createFromTimestamp(1700000000)->format('U.u'), "\n";
echo DateTimeImmutable::createFromTimestamp(1700000000.5)->format('U.u'), "\n";
echo DateTimeImmutable::createFromTimestamp(1700000000.5)->format('u'), "\n";
echo DateTimeImmutable::createFromTimestamp(1700000000.5)->getMicrosecond(), "\n";
