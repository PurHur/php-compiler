<?php
$s = 'a,b,c';
echo strtok($s, ','), '|';
echo strtok(null, ','), "\n";
