--TEST--
stdlib DateInterval::createFromDateString() next/last day, yesterday, tomorrow (#27954, ext/date)
--FILE--
<?php
foreach (['next day', 'last day', 'yesterday', 'tomorrow', '1 day', 'previous day', 'this day', 'NEXT DAY'] as $s) {
    $i = @DateInterval::createFromDateString($s);
    if ($i === false) {
        echo $s, " => false\n";
    } else {
        echo $s, ' => d=', $i->d, ' invert=', $i->invert, "\n";
    }
}
$fn = @date_interval_create_from_date_string('tomorrow');
echo 'fn tomorrow => d=', $fn->d, ' invert=', $fn->invert, "\n";
?>
--EXPECT--
next day => d=1 invert=0
last day => d=-1 invert=0
yesterday => d=-1 invert=0
tomorrow => d=1 invert=0
1 day => d=1 invert=0
previous day => d=-1 invert=0
this day => d=0 invert=0
NEXT DAY => d=1 invert=0
fn tomorrow => d=1 invert=0
