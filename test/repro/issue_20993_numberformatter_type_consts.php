<?php
declare(strict_types=1);

foreach (['TYPE_DEFAULT', 'TYPE_INT32', 'TYPE_DOUBLE', 'DECIMAL_ALWAYS_SHOWN', 'PAD_BEFORE_PREFIX', 'PATTERN_RULEBASED', 'IGNORE'] as $c) {
    $full = 'NumberFormatter::'.$c;
    echo $c, '=', defined($full) ? constant($full) : 'undef', "\n";
}
$fmt = NumberFormatter::create('en_US', NumberFormatter::DECIMAL);
echo $fmt->format(1.5, NumberFormatter::TYPE_INT32), "\n";
echo $fmt->format(1.5, NumberFormatter::TYPE_DOUBLE), "\n";
