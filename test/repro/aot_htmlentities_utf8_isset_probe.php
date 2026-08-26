<?php
// #35050 — htmlentities UTF-8 entity map must fire under NestedJIT (peer #35045)
echo htmlentities('café', ENT_QUOTES, 'UTF-8'), "\n";
echo htmlentities('é', ENT_HTML5, 'UTF-8'), "\n";
echo htmlentities('<a>&b', ENT_QUOTES, 'UTF-8'), "\n";
echo htmlentities('noop', ENT_QUOTES, 'UTF-8'), "\n";
