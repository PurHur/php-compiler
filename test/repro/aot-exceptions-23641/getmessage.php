<?php
$e = new LogicException("boom");
echo "created\n";
echo "msg=", $e->getMessage(), "\n";
