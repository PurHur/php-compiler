<?php
// #25146 — hrtime() zero-arg / named runtime unchanged under AOT (Reflection in compliance phpt).
$pair = hrtime();
echo 'zero=', is_array($pair) ? count($pair) : 'n/a';
$named = hrtime(as_number: false);
echo ' named=', is_array($named) ? count($named) : 'n/a';
echo "\n";
