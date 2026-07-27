<?php
$e = new LogicException("boom");
echo "class=", get_class($e), "\n";
echo "throwable=", ($e instanceof \Throwable ? "Y" : "N"), "\n";
echo "exception=", ($e instanceof \Exception ? "Y" : "N"), "\n";
echo "msg=", $e->getMessage(), "\n";
