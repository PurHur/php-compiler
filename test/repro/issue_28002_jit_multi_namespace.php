<?php
namespace A {
  function f(){ return 1; }
}
namespace {
  echo \A\f(), PHP_EOL;
}
