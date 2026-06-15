<?php
$old = error_reporting(0);
echo error_reporting() === 0 ? "zero\n" : "not-zero\n";
error_reporting($old);
echo error_reporting() === $old ? "restored\n" : "restore-fail\n";
