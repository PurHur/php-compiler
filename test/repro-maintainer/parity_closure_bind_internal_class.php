<?php
$fn = function () { return 1; };
var_dump(Closure::bind($fn, new stdClass(), 'stdClass'));
