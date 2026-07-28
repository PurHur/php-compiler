--TEST--
AOT: match on int literals with default arm (issue #143, #2398, #24143)
--FILE--
<?php
// Concat (not comma-echo) so consecutive matches stay in separate CFG blocks.
// Comma-echo `echo match(...), "\n"; echo match(...)` still shares a merge block
// and needs a follow-up for the trailing-stmt case (#24143).
echo match (2) {
    1 => 'a',
    2 => 'b',
    default => 'c',
} . "\n";
echo match ('GET') {
    'GET' => 'get',
    'POST' => 'post',
    default => 'other',
} . "\n";
--EXPECT--
b
get
