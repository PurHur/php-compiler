<?php
// #32130 — diamond trait insteadof must say "Required Trait DA wasn't added"
trait DA {}
trait DB { use DA; }
trait DC { use DA; }
class DD {
    use DB, DC {
        DA::m insteadof DB, DC;
    }
}
echo "unreached\n";
