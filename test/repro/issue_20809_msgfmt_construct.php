<?php
// Repro for #20809 — MessageFormatter::__construct must match create()
$a = new MessageFormatter('en_US', 'Hello {0}');
$b = MessageFormatter::create('en_US', 'Hello {0}');
echo 'new_format=';
var_export($a->format(['World']));
echo "\n";
echo 'create_format=';
var_export($b->format(['World']));
echo "\n";
echo 'new_pattern=';
var_export($a->getPattern());
echo "\n";
echo 'create_pattern=';
var_export($b->getPattern());
echo "\n";
