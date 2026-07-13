<?php

$out20 = number_format(1.1, 20);
echo 'decimals20=', $out20, "\n";

$outNeg = number_format(1.5, -1);
echo 'decimals_neg=', $outNeg, "\n";

$expected20 = '1.10000000000000008882';
if ($out20 !== $expected20) {
    fwrite(STDERR, "expected20=$expected20 got=$out20\n");
    exit(1);
}
if ('2' !== $outNeg) {
    fwrite(STDERR, "expected neg decimals=2 got=$outNeg\n");
    exit(1);
}
