<?php
/** Issue #26971 — thin AOT array_pad() must print padded list (not segfault). */
echo implode(',', array_pad([1], 3, 0)), "\n";
echo implode(',', array_pad([1], -3, 0)), "\n";
