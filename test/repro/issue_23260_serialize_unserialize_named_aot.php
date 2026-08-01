<?php
// AOT probe #23260 — named unserialize binds Zend stub names at compile+run.
// serialize() AOT *runtime* segfaults on master for positional forms too (pre-existing);
// compile of serialize(value:) is checked separately in the PR transcript.
echo unserialize(data: 'i:1;'), "\n";
echo unserialize(data: 'i:2;', options: []), "\n";
echo unserialize('i:3;'), "\n";
