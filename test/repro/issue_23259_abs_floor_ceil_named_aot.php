<?php
// AOT probe #23259 — abs named num: (floor/ceil AOT positional already return 0 on master)
echo abs(num: -3), "\n";
echo abs(-3), "\n";
