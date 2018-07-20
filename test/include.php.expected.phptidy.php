<?php
/**
 * include.php
 *
 * @package default
 */


require "function_blank_lines.php";

require_once 'function_docblocks.php';

include_once "function_return_reference.php";

include DOCROOT."indent.php";

include $docroot . 'indent_elseif.php';

include $x."include.php";

include "include".$x.".php";

include $x;

include $x;

include $x.".php";

include "doesn't_exist";
