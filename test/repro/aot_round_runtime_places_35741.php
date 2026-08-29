<?php
function pick(): float
{
    return 1.55;
}
$y = pick();
echo round($y, 1), "\n";
echo round(log1p(1), 5), "\n";
echo round(M_PI, 5), '|', round(pi(), 5), "\n";
