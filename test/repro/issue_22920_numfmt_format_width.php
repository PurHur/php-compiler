<?php
// Repro for #22920 — FORMAT_WIDTH + PADDING_* in format().
$fmt = new NumberFormatter('en_US', NumberFormatter::DECIMAL);
echo 'WIDTH=', var_export($fmt->getAttribute(NumberFormatter::FORMAT_WIDTH), true), "\n";
echo 'PADPOS=', var_export($fmt->getAttribute(NumberFormatter::PADDING_POSITION), true), "\n";
$fmt->setAttribute(NumberFormatter::FORMAT_WIDTH, 8);
$fmt->setAttribute(NumberFormatter::PADDING_POSITION, NumberFormatter::PAD_BEFORE_PREFIX);
$fmt->setTextAttribute(NumberFormatter::PADDING_CHARACTER, '*');
echo 'pad=', var_export($fmt->format(12), true), "\n";
