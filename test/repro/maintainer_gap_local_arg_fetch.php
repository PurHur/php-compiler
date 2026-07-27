<?php
function show_local() {
  $x = 42;
  echo "echo=", $x, "\n";
  var_dump($x + 0); // OK on VM
  var_dump($x);     // Undefined variable on VM/JIT
}
show_local();

function pass_to_user() {
  $x = 7;
  sink($x); // $x undefined at call site on VM/JIT
}
function sink($v) { var_dump($v); }
pass_to_user();
