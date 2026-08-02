<?php
// Repro #26994 — AOT fmod must match Zend/VM (not return 0).
echo fmod(5.7, 1.3), "\n";
