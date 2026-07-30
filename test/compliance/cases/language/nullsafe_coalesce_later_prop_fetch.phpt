--TEST--
Language: nullsafe ?-> + ?? must not skip later property fetch echo (#25525)
--FILE--
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

// Null child: coalesce takes RHS; later property fetch still runs.
$u2 = new U();
$t2 = $u2->child?->name ?? "x";
echo "t2=$t2\n";
echo "name2=".$u2->name."\n";
--EXPECT--
t=n
name=n
done
t2=x
name2=n
