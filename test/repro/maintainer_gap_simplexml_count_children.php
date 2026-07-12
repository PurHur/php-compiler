<?php
declare(strict_types=1);

$x = simplexml_load_string('<r><a/><b/></r>');
if (false === $x) {
    echo "load_failed\n";
    exit(1);
}
$count = count($x);
echo 'count='.$count."\n";
exit(2 === $count ? 0 : 1);
