<?php
// Repro #31839 — user-script strncmp stays PHP SSOT after LibcExtern always-on drop.
echo strncmp('abc', 'abd', 2), "\n";
echo strncmp('abc', 'abc', 3), "\n";
