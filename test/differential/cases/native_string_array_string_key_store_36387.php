<?php
// Nested list<string> under string key — native __string__*[] must materialize (#36387)
//
// @differential-repeat: 3 heap/refcount path on nested array store

$map = [];
$map['k'] = ['v'];
echo $map['k'][0], "\n";
