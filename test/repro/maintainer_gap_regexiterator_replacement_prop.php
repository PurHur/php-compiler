<?php
$rc = new ReflectionClass("RegexIterator");
echo "hasProperty=", $rc->hasProperty("replacement") ? "Y" : "N", "\n";
$it = new RegexIterator(new ArrayIterator(["a1", "bb", "c3"]), "/(\\d)/", 4);
echo "property_exists=", property_exists($it, "replacement") ? "Y" : "N", "\n";
echo "default=", var_export($it->replacement, true), "\n";
$it->replacement = "X";
echo "result=", implode(",", iterator_to_array($it)), "\n";
