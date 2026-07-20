<?php

/**
 * Repro for #21110 — NumberFormatter rule-based styles via ICU.
 */
$spell = new NumberFormatter('en', NumberFormatter::SPELLOUT);
echo $spell->format(42), PHP_EOL;
$ord = new NumberFormatter('en', NumberFormatter::ORDINAL);
echo $ord->format(42), PHP_EOL;
$dur = new NumberFormatter('en', NumberFormatter::DURATION);
echo $dur->format(3661), PHP_EOL;
