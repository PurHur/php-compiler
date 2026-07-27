<?php
$e = new LogicException("boom");
var_export($e instanceof Throwable);
echo "\n";
