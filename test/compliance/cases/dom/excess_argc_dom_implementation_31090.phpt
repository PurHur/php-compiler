--TEST--
DOMImplementation createDocument/hasFeature excess argc → ArgumentCountError (#31090)
--RUNFILE--
../../../repro/maintainer_gap_dom_implementation_excess_argc.php
--EXPECT--
DOMImplementation::createDocument() expects at most 3 arguments, 4 given
DOMImplementation::hasFeature() expects exactly 2 arguments, 3 given
createOK
featOK
create0OK
