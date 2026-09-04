<?php
class C {
  protected $StrongRegex = ['*' => '/^[*]{2}(.+?)[*]{2}/s'];
  function f($Excerpt) {
    $marker = $Excerpt['text'][0];
    if ($Excerpt['text'][1] === $marker and preg_match($this->StrongRegex[$marker], $Excerpt['text'], $matches)) {
      echo "yes m=", json_encode($matches), "\n";
    } else {
      echo "no m=", isset($matches) ? json_encode($matches) : 'U', "\n";
    }
  }
}
(new C())->f(['text'=>'**world**']);
