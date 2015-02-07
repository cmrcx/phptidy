<?

function &f() {
	return $GLOBALS['z'];
}

$z = 1;
$y =& f();
