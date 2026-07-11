<?php
$fp = fopen('php://memory', 'r');
$mode = fstat($fp)['mode'];
printf('%o', $mode);
