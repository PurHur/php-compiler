<?php
function run(string $label, callable $fn) {
  echo "== $label ==\n";
  try { $fn(); echo "OK\n"; } catch (Throwable $e) { echo get_class($e), ": ", $e->getMessage(), "\n"; }
}
run('backed get/set append', function() {
  class A { private array $s=[]; public array $items { get=>$this->s; set{$this->s=$value;} } }
  $a=new A; $a->items[]=1; echo json_encode($a->items),"\n";
});
run('self-backed dim write', function() {
  class B { public array $items { get=>$this->items??[]; set{$this->items=$value;} } }
  $b=new B; $b->items['k']='v'; echo json_encode($b->items),"\n";
});
run('virtual get+set dim', function() {
  class C { public array $items { get=>['a'=>1]; set{} } }
  $c=new C; $c->items['a']=99; echo json_encode($c->items),"\n";
});
run('&get append', function() {
  class D { private array $a=[]; public array $items { &get=>$this->a; } }
  $d=new D; $d->items[]=1; echo json_encode($d->items),"\n";
});
run('get-only no set dim', function() {
  class E { private array $a=[1]; public array $items { get=>$this->a; } }
  $e=new E; $e->items[0]=9; echo json_encode($e->items),"\n";
});
run('virtual get-only dim', function() {
  class F { public array $items { get=>['a'=>1]; } }
  $f=new F; $f->items['a']=99; echo json_encode($f->items),"\n";
});
run('unset dim backed', function() {
  class G { private array $s=['k'=>1]; public array $items { get=>$this->s; set{$this->s=$value;} } }
  $g=new G; unset($g->items['k']); echo json_encode($g->items),"\n";
});
run('&get+set virtual append', function() {
  class H { private array $s=[]; public array $items { &get=>$this->s; set{$this->s=$value;} } }
  $h=new H; $h->items[]=1; echo json_encode($h->items),"\n";
});
