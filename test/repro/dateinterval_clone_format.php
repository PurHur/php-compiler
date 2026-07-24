<?php
declare(strict_types=1);
// #22893 — clone(DateInterval) must not crash; format/props match Zend.
$i = new DateInterval('P1Y2M3DT4H5M6S');
$c = clone $i;
echo $c->format('%Y-%M-%D'), "\n";
echo 'y=', $c->y, ' m=', $c->m, ' d=', $c->d, "\n";
$f = DateInterval::createFromDateString('1 day');
$cf = clone $f;
echo 'from=', $cf->format('%d'), "\n";
echo 'gov=', json_encode(get_object_vars($cf)), "\n";
