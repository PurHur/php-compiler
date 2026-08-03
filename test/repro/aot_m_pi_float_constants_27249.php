<?php

/** #27249 — AOT M_PI / float math constants must not truncate through round(). */
echo round(M_PI, 5), '|', round(pi(), 5), "\n";
echo round(M_E, 5), "\n";
echo round(M_SQRT2, 5), "\n";
