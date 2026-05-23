--TEST--
AOT wordwrap()
--FILE--
<?php
$s = 'The quick brown fox jumped over the lazy dog.';
echo wordwrap($s, 20, "\n"), "\n";
echo wordwrap('supercalifragilistic', 5, '|', true), "\n";
echo wordwrap('hello world', 5, '-'), "\n";
--EXPECT--
The quick brown fox
jumped over the lazy
dog.
super|calif|ragil|istic
hello-world
