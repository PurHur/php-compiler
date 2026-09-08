<?php
// @differential-repeat: 3 NestedJIT chunk_split scratch was intermittent (#36388)
// php-src: ext/standard/string.c PHP_FUNCTION(chunk_split)
echo chunk_split('abcd', 2, ':'), "\n";
echo chunk_split('abcdef', 2, ':'), "\n";
echo chunk_split('', 4, 'X'), "\n";
echo chunk_split('hi', 1, '-'), "\n";
