--TEST--
DateInterval::createFromDateString named datetime (#24589)
--FILE--
<?php
$i = DateInterval::createFromDateString(datetime: '1 day');
echo 'named_d=', $i->format('%d'), "\n";
--EXPECT--
named_d=1
