<?php
// AOT compile-only: str_padded() UTF-8 padding (#7044, ext/standard/string.c).
echo str_padded('hi', 5), "\n";
echo str_padded('hi', 5, '0', 0), "\n";
echo str_padded('abc', 6, '-', 2), "\n";
