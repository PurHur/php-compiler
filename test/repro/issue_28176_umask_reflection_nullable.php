<?php
$r = new ReflectionFunction("umask");
$p = $r->getParameters()[0];
echo "name=", $p->getName(), " type=", (string)$p->getType(), " default=", json_encode($p->getDefaultValue()), PHP_EOL;
