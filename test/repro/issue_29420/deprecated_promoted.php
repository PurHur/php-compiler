<?php
class C {
  public function __construct(
    #[\Deprecated('old')]
    public $x = 1,
  ) {}
}
new C(2);
