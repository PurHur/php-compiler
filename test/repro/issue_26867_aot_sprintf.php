<?php
/** Repro for #26867 — AOT sprintf zero-pad / custom pad. */
echo sprintf("%'#10s", "x"), "\n";
echo sprintf("%05d", 42), "\n";
echo vsprintf("%05d", [42]), "\n";
