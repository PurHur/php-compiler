--TEST--
Language: promoted ctor typed set(string $v) matches property type (#29673, zend_compile.c)
--DESCRIPTION--
Constructor-promoted hooked properties with an explicit typed set parameter matching the
promoted property type must compile and run. Short-form set { $value } and non-promoted
typed set(string $v) already worked; only the promoted + named typed param form was rejected.
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
putenv('PHP_COMPILER_PROFILE=8.4');
if (!PHPCompiler\CompilerVersion::supportsPropertyHooks()) {
    die('skip requires PHP_COMPILER_PROFILE=8.4 property hooks gate');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class C {
    public function __construct(
        public string $name {
            set(string $v) { $this->name = strtoupper($v); }
        }
    ) {}
}
$c = new C('hi');
echo $c->name, "\n";

class Short {
    public function __construct(
        public string $name {
            set { $this->name = strtoupper($value); }
        }
    ) {}
}
$s = new Short('hi');
echo $s->name, "\n";

class NonPromo {
    public string $name {
        set(string $v) { $this->name = strtoupper($v); }
    }
    public function __construct(string $name) { $this->name = $name; }
}
$n = new NonPromo('hi');
echo $n->name, "\n";
--EXPECT--
HI
HI
HI
