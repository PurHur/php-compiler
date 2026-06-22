<?php

$r = @iconv('UTF-8', 'INVALID//IGNORE', 'hello');
var_export($r);
