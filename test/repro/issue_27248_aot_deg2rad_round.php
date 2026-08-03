<?php

/**
 * #27248 — AOT round(deg2rad(180), 5) must print 3.14159 (not truncated 3).
 * NestedJIT RoundJitHelper places>0 cold path (#27249); deg2rad alone is fine.
 * Single round() call — a second round() in the same binary masks the defect.
 */
echo round(deg2rad(180), 5), "\n";
