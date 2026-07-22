--TEST--
DateTime/DateTimeImmutable format constants on class entries — defined()/Reflection (#22271, ext/date/php_date.c)
--FILE--
<?php
echo defined('DateTime::ATOM') ? '1' : '0', "\n";
echo defined('DateTimeImmutable::ATOM') ? '1' : '0', "\n";
echo defined('DateTime::RFC3339_EXTENDED') ? '1' : '0', "\n";
echo defined('DateTimeImmutable::RFC7231') ? '1' : '0', "\n";
$r = new ReflectionClass(DateTime::class);
echo $r->hasConstant('ATOM') ? '1' : '0', "\n";
echo $r->hasConstant('RFC3339') ? '1' : '0', "\n";
$consts = $r->getConstants();
ksort($consts);
echo count($consts), "\n";
var_export($consts);
echo "\n";
$ri = new ReflectionClass(DateTimeImmutable::class);
echo count($ri->getConstants()), "\n";
echo DateTime::ATOM, "\n";
echo DateTimeImmutable::W3C, "\n";
--EXPECT--
1
1
1
1
1
1
14
array (
  'ATOM' => 'Y-m-d\\TH:i:sP',
  'COOKIE' => 'l, d-M-Y H:i:s T',
  'ISO8601' => 'Y-m-d\\TH:i:sO',
  'ISO8601_EXPANDED' => 'X-m-d\\TH:i:sP',
  'RFC1036' => 'D, d M y H:i:s O',
  'RFC1123' => 'D, d M Y H:i:s O',
  'RFC2822' => 'D, d M Y H:i:s O',
  'RFC3339' => 'Y-m-d\\TH:i:sP',
  'RFC3339_EXTENDED' => 'Y-m-d\\TH:i:s.vP',
  'RFC7231' => 'D, d M Y H:i:s \\G\\M\\T',
  'RFC822' => 'D, d M y H:i:s O',
  'RFC850' => 'l, d-M-y H:i:s T',
  'RSS' => 'D, d M Y H:i:s O',
  'W3C' => 'Y-m-d\\TH:i:sP',
)
14
Y-m-d\TH:i:sP
Y-m-d\TH:i:sP
