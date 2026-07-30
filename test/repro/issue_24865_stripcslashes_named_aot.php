<?php
// AOT-positive probe #24865 — named string: (rejection of str: is VM/JIT-only; AOT throws at compile)
echo stripcslashes(string: "a\\nb"), PHP_EOL;
