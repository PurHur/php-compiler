--TEST--
stdlib range() multi-char non-numeric strings use first byte (#28830, ext/standard/array.c)
--FILE--
<?php
echo implode(',', range('a', 'zz')), "\n";
echo implode(',', range('a1', 'c')), "\n";
echo implode(',', range('aa', 'c')), "\n";
echo implode(',', range('a', 'cc')), "\n";
echo implode(',', range('zz', 'a')), "\n";
echo implode(',', range('a', 'c')), "\n";
echo implode(',', range(1, 3)), "\n";
--EXPECT--
a,b,c,d,e,f,g,h,i,j,k,l,m,n,o,p,q,r,s,t,u,v,w,x,y,z
a,b,c
a,b,c
a,b,c
z,y,x,w,v,u,t,s,r,q,p,o,n,m,l,k,j,i,h,g,f,e,d,c,b,a
a,b,c
1,2,3
