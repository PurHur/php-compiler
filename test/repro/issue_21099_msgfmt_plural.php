<?php

/**
 * Repro for #21099 — MessageFormatter ICU plural/select.
 */
$fmt = new MessageFormatter('en_US', '{n, plural, =0{none} one{# item} other{# items}}');
echo $fmt->format(['n' => 0]), "\n";
echo $fmt->format(['n' => 1]), "\n";
echo $fmt->format(['n' => 5]), "\n";
