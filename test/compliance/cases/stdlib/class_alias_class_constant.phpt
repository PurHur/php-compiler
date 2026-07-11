--TEST--
Stdlib: class_alias() — alias ::class returns alias name (#15645)
--FILE--
<?php
class Real15645 {
    public static function tag(): string {
        return 'real';
    }
}
class_alias(Real15645::class, 'Alias15645');
echo Real15645::class, "\n";
echo Alias15645::class, "\n";
echo get_class(new Alias15645()), "\n";

interface Iface15645 {}
class_alias('Iface15645', 'IfaceAlias15645');
echo Iface15645::class, "\n";
echo IfaceAlias15645::class, "\n";

trait Trait15645 {}
class_alias('Trait15645', 'TraitAlias15645');
echo Trait15645::class, "\n";
echo TraitAlias15645::class, "\n";
--EXPECT--
Real15645
Alias15645
Real15645
Iface15645
IfaceAlias15645
Trait15645
TraitAlias15645
