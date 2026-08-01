<?php
foreach (['getmyuid', 'getmygid', 'getmypid', 'getlastmod'] as $fn) {
    $r = new ReflectionFunction($fn);
    echo $fn, ' ret=', (string) $r->getReturnType(), "\n";
}
echo 'pid=', getmypid(), "\n";
echo 'uid=', getmyuid(), "\n";
echo 'gid=', getmygid(), "\n";
$lm = getlastmod();
echo 'lastmod=', (false === $lm ? 'false' : (string) $lm), "\n";
