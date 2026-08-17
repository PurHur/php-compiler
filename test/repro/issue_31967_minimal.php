<?php
/** Minimal AOT repro for #31967 — numeric-string + and NAN spaceship. */
echo "5" + 5, "\n";
echo NAN <=> 1.0, "\n";
