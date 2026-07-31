--TEST--
preg_replace_callback inaccessible private/protected callback TypeError (#25735)
--FILE--
<?php
class PregInaccP {
    private function fmt($m) {
        return '['.$m[0].']';
    }

    protected function fmtProt($m) {
        return '{'.$m[0].'}';
    }

    public function run() {
        echo preg_replace_callback('/a/', [$this, 'fmt'], 'a-a'), "\n";
    }
}
class PregInaccC extends PregInaccP {
    public function runProt() {
        echo preg_replace_callback('/a/', [$this, 'fmtProt'], 'a'), "\n";
    }
}
(new PregInaccP())->run();
(new PregInaccC())->runProt();
$p = new PregInaccP();
try {
    echo preg_replace_callback('/a/', [$p, 'fmt'], 'a'), "\n";
    echo "private uncaught\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    echo preg_replace_callback('/a/', [$p, 'fmtProt'], 'a'), "\n";
    echo "protected uncaught\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
[a]-[a]
{a}
TypeError: preg_replace_callback(): Argument #2 ($callback) must be a valid callback, cannot access private method PregInaccP::fmt()
TypeError: preg_replace_callback(): Argument #2 ($callback) must be a valid callback, cannot access protected method PregInaccP::fmtProt()
