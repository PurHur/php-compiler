<?php
declare(strict_types=1);

class MaintainerArrayUniqueObject {
    public function __construct(public int $v) {}
}

$in = [new MaintainerArrayUniqueObject(1), new MaintainerArrayUniqueObject(1), new MaintainerArrayUniqueObject(2)];
$out = array_unique($in, SORT_REGULAR);
echo count($out), "\n";
foreach ($out as $o) {
    echo $o->v, "\n";
}
