<?php
// #23485 — array === must require identical element types (Zend is_identical_function)
var_export([1, 2] === [1, '2']); echo "\n";
var_export([1.0] === [1]); echo "\n";
var_export([[1]] === [['1']]); echo "\n";
// Positive controls: loose == still juggles; identical same-type stays true
var_export([1, 2] == [1, '2']); echo "\n";
var_export([1, 2] === [1, 2]); echo "\n";
var_export([1, 2] !== [1, '2']); echo "\n";
