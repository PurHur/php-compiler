<?php

$s = 'a,b,c';
echo strtok($s, ','), '|', strtok(null, ','), '|', strtok(null, ','), PHP_EOL;
