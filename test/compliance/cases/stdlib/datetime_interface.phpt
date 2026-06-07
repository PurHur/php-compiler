--TEST--
stdlib DateTimeInterface built-in interface — instanceof, constants, typed params (#7141, ext/date/php_date.h)
--FILE--
<?php
echo interface_exists('DateTimeInterface') ? '1' : '0', "\n";
echo (new DateTime('2026-01-01')) instanceof DateTimeInterface ? '1' : '0', "\n";
echo DateTimeInterface::ATOM, "\n";
echo DateTimeInterface::RFC3339, "\n";
function accepts(DateTimeInterface $dt): string {
    return $dt->format('Y-m-d');
}
echo accepts(new DateTime('2026-06-07')), "\n";
--EXPECT--
1
1
Y-m-d\TH:i:sP
Y-m-d\TH:i:sP
2026-06-07
