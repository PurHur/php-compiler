<?php

declare(strict_types=1);

$g = sprintf('%g', -0.0);
$G = sprintf('%G', -0.0);
echo $g, "\n", $G, "\n";
echo ($g === '-0' && $G === '-0') ? 'ok' : 'fail', "\n";
