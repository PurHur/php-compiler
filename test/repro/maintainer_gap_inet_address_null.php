<?php

$ntop = inet_ntop(null);
$pton = inet_pton(null);
if (false === $ntop && false === $pton) {
    echo "ok\n";
} else {
    echo 'fail ntop=', var_export($ntop, true), ' pton=', var_export($pton, true), "\n";
}
