<?php
// Repro #13508 — fmod(-1.5, 1.2) after round() must not read polluted slot.
round(2.5, 0, PHP_ROUND_HALF_UP);
echo 'fmod_neg=' . fmod(-1.5, 1.2) . "\n";
