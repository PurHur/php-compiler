<?php
class U {
  public string $name = "n";
  public ?U $child = null;
}
$u = new U();
$u->child = new U();
$t = $u->child?->name ?? "x";
echo "t=$t\n";
echo "name=".$u->name."\n";
echo "done\n";
