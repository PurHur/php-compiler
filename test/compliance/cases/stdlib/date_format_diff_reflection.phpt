--TEST--
stdlib date_format/date_diff Reflection DateTimeInterface + string return (#30245, ext/date/php_date.stub.php)
--FILE--
<?php
foreach (['date_format', 'date_diff'] as $fn) {
    $r = new ReflectionFunction($fn);
    echo $fn, ' ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'NONE', "\n";
    foreach ($r->getParameters() as $p) {
        echo '  ', $p->getName(), ' ty=', $p->hasType() ? (string) $p->getType() : '-', "\n";
    }
}
$dt = new DateTimeImmutable('2020-01-01');
echo 'format=', date_format(object: $dt, format: 'Y'), "\n";
$diff = date_diff(baseObject: $dt, targetObject: new DateTimeImmutable('2020-01-02'));
echo 'diff=', $diff->days, "\n";
--EXPECT--
date_format ret=string
  object ty=DateTimeInterface
  format ty=string
date_diff ret=DateInterval
  baseObject ty=DateTimeInterface
  targetObject ty=DateTimeInterface
  absolute ty=bool
format=2020
diff=1
