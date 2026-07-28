<?php
// #24220 — thin standalone AOT scalar bridge for var_dump(null) and var_dump(string).
//
//   var_dump(null)   -> NULL
//   var_dump('hi')   -> string(2) "hi"
//
// Literal null arrives as a null __value__* at the callee; the thin bridge must guard before load.
var_dump('hi');
var_dump(null);
