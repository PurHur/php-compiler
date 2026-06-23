<?php
$dt = DateTime::createFromFormat('!H:i', '14:30');
echo $dt->format('Y-m-d H:i:s'), "\n";
$dt2 = date_create_from_format('!H:i', '14:30');
echo $dt2->format('Y-m-d H:i:s'), "\n";
