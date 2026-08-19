<?php
$fns = ['chown', 'chgrp', 'lchown', 'lchgrp'];
foreach ($fns as $fn) {
    $r = new ReflectionFunction($fn);
    foreach ($r->getParameters() as $p) {
        $t = $p->getType();
        echo "$fn::", $p->getName(), ":", ($t ? (string)$t : "none"), "\n";
    }
}
