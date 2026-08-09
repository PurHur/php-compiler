<?php
// #29504 — AOT smoke: null $offset coerces to 0 (DEP verified on VM/JIT).
echo substr_compare('abc', 'b', null), "\n";
