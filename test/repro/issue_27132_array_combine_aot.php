<?php
/** Issue #27132 — thin AOT array_combine() must print combined object (not segfault). */
echo json_encode(array_combine(['a', 'b'], [1, 2])), "\n";
