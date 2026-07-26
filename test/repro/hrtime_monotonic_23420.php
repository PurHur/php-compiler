<?php
$prev = null;
$bad = 0;
for ($i = 0; $i < 50; $i++) {
    $n = hrtime(true);
    if ($prev !== null && $n < $prev) {
        $bad++;
    }
    $prev = $n;
}
echo "as_number_bad=$bad\n";
$prev = null;
$bad = 0;
for ($i = 0; $i < 50; $i++) {
    [$s, $ns] = hrtime();
    $n = $s * 1_000_000_000 + $ns;
    if ($prev !== null && $n < $prev) {
        $bad++;
    }
    $prev = $n;
}
echo "pair_bad=$bad\n";
