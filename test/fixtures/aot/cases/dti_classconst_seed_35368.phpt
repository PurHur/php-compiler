--TEST--
DateTimeInterface::ATOM ClassConstFetch seeds for AOT (#35368)
--FILE--
<?php
echo DateTimeInterface::ATOM, "\n";
echo DateTimeInterface::COOKIE, "\n";
echo DateTimeInterface::RFC3339, "\n";
--EXPECT--
Y-m-d\TH:i:sP
l, d-M-Y H:i:s T
Y-m-d\TH:i:sP
