<?php
// Issue #30859 — chunk_split thin AOT must match Zend (no segfault).
echo chunk_split("abcd", 2, ":"), "\n";
echo chunk_split("abcdef", 2, ":"), "\n";
echo chunk_split("hi", 1, "-"), "\n";
