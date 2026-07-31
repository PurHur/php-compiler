<?php
// Zend runs (dead `new`); VM must not parseAndCompile-fatal (#25787 / re-#3385).
abstract class A {}
echo "alive\n";
if (false) {
    new A();
}
echo "done\n";
