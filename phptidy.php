#!/usr/bin/env php
<?php
/**
 * phptidy
 *
 * See README for more information.
 *
 * PHP version >= 5
 *
 * @copyright 2003-2019 Magnus Rosenbaum
 * @license   GPL v2
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 675 Mass Ave, Cambridge, MA 02139, USA.
 *
 * @version 3.2 (2019-08-10)
 * @author  Magnus Rosenbaum <phptidy@cmr.cx>
 * @package phptidy
 */


//////////////// DEFAULT CONFIGURATION ///////////////////

// You can overwrite all these settings in your configuration files.

// List of files in your project
// Wildcards for glob() may be used.
// Example: array("*.php", "inc/*.php");
$project_files = array();

// List of files you want to exclude from the project files
// Wildcards are not allowed here.
// Example: array("inc/external_lib.php");
$project_files_excludes = array();

// diff command
// Examples: "diff", "colordiff", "diff -u", "colordiff -u"
$diff = "colordiff -u";

// The automatically added author in the phpdoc file docblocks
// If left empty no new @author doctags will be added.
// Example: "Your Name <you@example.com>"
$default_author = "";

// Name of the automatically added @package doctag in the phpdoc file docblocks
// Example: "myproject"
$default_package = "default";

// String used for indenting
// If you indent with spaces you can use as much spaces as you like.
// Useful values: "\t" for indenting with tabs,
//                "  " for indenting with two spaces
$indent_char = "\t";

// Control structures with the opening curly brace on a new line
// Examples: false                      always on the same line
//           true                       always on a new line
//           array(T_CLASS, T_FUNCTION) for PEAR Coding Standards
$curly_brace_newline = false;

// PHP open tag
// All php open tags will be replaced by the here defined kind of open tag.
// Useful values: "<?", "<?php", "<?PHP"
$open_tag = "<?php";

// Keep short open echo tags even when using long open tags
// Values: false  if long open tags are used, convert <?= to open tag and echo
//         true   always leave <?= untouched
$keep_open_echo_tags = false;

// Check encoding
// If left empty the encoding will not be checked.
// See http://php.net/manual/en/ref.mbstring.html for a list of supported
// encodings.
// Examples: "ASCII", "UTF-8", "ISO-8859-1"
$encoding = "";

// Docroot-Variables
// phptidy will strip these variables and constants from the beginning of
// include and require commands to generate appropriate @see tags also for
// these files.
// Example: array('DOCROOT', '$docroot');
$docrootvars = array();

// Enable the single cleanup functions
$fix_token_case             = true;
$fix_builtin_functions_case = true;
$replace_inline_tabs        = true;
$replace_phptags            = true;
$replace_shell_comments     = true;
$fix_statement_brackets     = true;
$fix_separation_whitespace  = true;
$fix_comma_space            = true;
$add_operator_space         = false;
$fix_round_bracket_space    = false;
$add_file_docblock          = true;
$add_function_docblocks     = true;
$add_doctags                = true;
$add_usestags               = false;
$fix_docblock_format        = true;
$fix_docblock_space         = true;
$add_blank_lines            = true;
$indent                     = true;

///////////// END OF DEFAULT CONFIGURATION ////////////////


define('CONFIGFILE', "./.phptidy-config.php");
define('CACHEFILE', "./.phptidy-cache");


error_reporting(E_ALL);
ini_set('display_errors', 'stderr');

if (!version_compare(phpversion(), "5", ">=")) {
	error("phptidy requires PHP 5 or newer.");
}
if (!extension_loaded("tokenizer")) {
	error("The 'Tokenizer' extension for PHP is missing. See http://php.net/manual/en/book.tokenizer.php for more information.");
}
if (php_sapi_name() != "cli") {
	error("phptidy has to be run on command line with CLI SAPI.");
}


// Read command line
$command = "";
$files = array();
$options = array();
foreach ( $_SERVER['argv'] as $key => $value ) {
	if ($key==0) continue;
	if ($key==1) {
		$command = $value;
		continue;
	}
	if (substr($value, 0, 1)=="-") {
		$options[] = $value;
	} else {
		$files[] = $value;
	}
}

// Get command
switch ($command) {
case "help":
case "--help":
case "-h":
	usage();
	exit;
case "-":
case "suffix":
case "replace":
case "diff":
case "source":
case "files":
case "tokens":
	break;
default:
	error("Unknown command: '".$command."'", true);
case "":
	usage();
	exit(1);
}

// Get options
$verbose = false;
$quiet = false;
$external_config_file = false;
foreach ( $options as $option ) {
	switch ($option) {
	case "-v":
	case "--verbose":
		$verbose = true;
		continue 2;
	case "-q":
	case "--quiet":
		$quiet = true;
		continue 2;
	}
	$option_array = explode('=', $option);
	if (count($option_array) == 2) {
		switch ($option_array[0]) {
		case "-c":
		case "--config":
			$external_config_file = $option_array[1];
			continue 2;
		}
	}
	error("Unknown option: '".$option."'", true);
}

// Load config file
if ( $external_config_file ) {
	display("Using external configuration file ".$external_config_file."\n");
	require $external_config_file;
	$configfile = $external_config_file;
} elseif ( file_exists(CONFIGFILE) ) {
	display("Using configuration file ".CONFIGFILE."\n");
	require CONFIGFILE;
	$configfile = CONFIGFILE;
} else {
	display("Running without configuration file\n");
	$configfile = false;
}

// Read code from STDIN and write formatted code to STDOUT
if ($command=="-") {
	$file    = null; // Don't use a file name
	$seetags = null; // Don't add any new seetags
	$source = file_get_contents("php://stdin");
	$functions = array_unique(get_functions($source));
	format($source);
	echo $source;
	exit;
}

// Files from config file
if (!count($files)) {
	if (!count($project_files)) {
		error("No files supplied on commandline and also no project files specified in config file.");
	}
	foreach ( $project_files as $pf ) {
		$files = array_unique(array_merge($files, glob($pf)));
	}
}

// File excludes from config file
foreach ( $project_files_excludes as $file_exclude ) {
	if (
		($key = array_search($file_exclude, $files)) !== false
	) unset($files[$key]);
}

// Check files
foreach ( $files as $key => $file ) {
	// Ignore backups and results from phptidy
	if (
		substr($file, -12)==".phptidybak~" or
		substr($file, -12)==".phptidy.php"
	) {
		unset($files[$key]);
		continue;
	}
	if ( !is_readable($file) or !is_file($file) ) {
		error("File '".$file."' does not exist or is not readable.");
	}
}

// Show files
if ($command=="files") {
	print_r($files);
	exit;
}

// Find functions and includes
display("Find functions and includes ");
$functions = array();
$seetags = array();
foreach ( $files as $file ) {
	display(".");
	$source = file_get_contents($file);
	$functions = array_unique(array_merge($functions, get_functions($source)));
	find_includes($seetags, $source, $file);
}
display("\n");
//print_r($functions);
//print_r($seetags);

// Read cache file
$new_cache = array(
	'setting' => md5(
		// Use cache only if none of this has changed
		file_get_contents($_SERVER['argv'][0]).
		($configfile ? file_get_contents($configfile) : '').
		serialize($functions).
		serialize($seetags)
	),
	'files' => array()
);
$cache = false;
$use_cache = false;
if ( file_exists(CACHEFILE) ) {
	display("Using cache file ".CACHEFILE."\n");
	$cache = unserialize(file_get_contents(CACHEFILE));
	if ( isset($cache['setting']) and $new_cache['setting'] == $cache['setting'] ) {
		$use_cache = true;
	}
}

display("Process files\n");
$replaced = 0;
foreach ( $files as $file ) {

	display(" ".$file."\n");
	$source = file_get_contents($file);

	// Cache
	$md5sum = md5($source);
	if ( $use_cache and isset($cache['files'][$file]) and $md5sum == $cache['files'][$file] ) {
		// Original file has not changed, so we don't process it
		if ($verbose) display("  File unchanged since last processing.\n");
		// Write md5sum of the skipped file into cache
		$new_cache['files'][$file] = $md5sum;
		continue;
	}

	// Check encoding
	if ($encoding and !mb_check_encoding($source, $encoding)) {
		display("  File contains characters which are not valid in ".$encoding.":\n");
		$source_converted = mb_convert_encoding($source, $encoding);
		$tmpfile = dirname($file).(dirname($file)?"/":"").".".basename($file).".phptidytmp~";
		if ( !file_put_contents($tmpfile, $source_converted) ) {
			error("The temporary file '".$tmpfile."' could not be saved.");
		}
		system($diff." ".$file." ".$tmpfile." 2>&1");
	}

	// Process source code
	$count = format($source);

	// Processing has not changed content of file
	if ( $count == 1 ) {
		if ($verbose) display("  Processed without changes.\n");
		// Write md5sum of the unchanged file into cache
		$new_cache['files'][$file] = $md5sum;
		continue;
	}

	// Output
	switch ($command) {
	case "suffix":

		$newfile = $file.".phptidy.php";
		if ( !file_put_contents($newfile, $source) ) {
			error("The file '".$newfile."' could not be saved.");
		}
		display("  ".$newfile." saved.\n");

		break;
	case "replace":

		$backupfile = dirname($file).(dirname($file)?"/":"").".".basename($file).".phptidybak~";
		if ( !copy($file, $backupfile) ) {
			error("The file '".$backupfile."' could not be saved.");
		}
		if ( !file_put_contents($file, $source) ) {
			error("The file '".$file."' could not be overwritten.");
		}
		display("  replaced.\n");
		++$replaced;

		// Write new md5sum into cache
		$new_cache['files'][$file] = md5($source);

		break;
	case "diff":

		$tmpfile = dirname($file).(dirname($file)?"/":"").".".basename($file).".phptidytmp~";
		if ( !file_put_contents($tmpfile, $source) ) {
			error("The temporary file '".$tmpfile."' could not be saved.");
		}
		system($diff." ".$file." ".$tmpfile." 2>&1");

		break;
	case "source":

		echo $source;

		break;
	}

}

if ($command=="replace") {
	if ($replaced) {
		display("Replaced ".$replaced." files.\n");
	}
	if ($new_cache != $cache) {
		display("Write cache file ".CACHEFILE."\n");
		if ( !file_put_contents(CACHEFILE, serialize($new_cache)) ) {
			display("Warning: The cache file '".CACHEFILE."' could not be saved.\n");
		}
	}
}


/////////////////// FUNCTIONS //////////////////////


/**
 * Display usage information
 */
function usage() {
	echo "
Usage: phptidy.php command [files|options]

Commands:
  suffix   Write output into files with suffix .phptidy.php
  replace  Replace files and backup original as .phptidybak
  diff     Show diff between old and new source
  source   Show formatted source code of affected files
  files    Show files that would be processed
  tokens   Show source file tokens
  -        Read code from STDIN and write formatted code to STDOUT
  help     Display this message

Options:
  -c=FILE, --config=FILE  Config file to be used
  -v,      --verbose      Verbose messages
  -q,      --quiet        Display only error messages

If no files are supplied on command line, they will be read from the config
file.

See README and source comments for more information.

";
}


/**
 * Display a message
 *
 * @param string  $msg Message with trailing linebreak
 */
function display($msg) {
	if ($GLOBALS['quiet']) return;
	fwrite(STDERR, $msg);
}


/**
 * Display an error message and exit
 *
 * @param string  $msg   Error message without trailing linebreak
 * @param boolean $usage (optional) Display usage information
 */
function error($msg, $usage=false) {
	fwrite(STDERR, "Error: ".$msg."\n");
	if ($usage) usage();
	exit(1);
}


/**
 * Format source code repeatedly until it is consistent
 *
 * @param string  $source (reference) Source code
 * @return integer       Number of repetitions
 */
function format(&$source) {
	$count = 0;
	do {
		$source_in = $source;
		$source = format_once($source_in);
		++$count;
		if ($count > 3) {
			display("  Code formatted 3 times and still not consistent!\n");
			break;
		}
	} while ( $source != $source_in );
	return $count;
}


/**
 * Format source code one time
 *
 * @param string  $source Original source code
 * @return string         Formatted source code
 */
function format_once($source) {

	// Replace non-Unix line breaks
	// http://pear.php.net/manual/en/standards.file.php
	// Windows line breaks -> Unix line breaks
	$source = str_replace("\r\n", "\n", $source);
	// Mac line breaks -> Unix line breaks
	$source = str_replace("\r", "\n", $source);

	$tokens = get_tokens($source);

	if ($GLOBALS['command']=="tokens") {
		print_tokens($tokens);
		exit;
	}

	// Simple formatting
	if ($GLOBALS['fix_token_case'])             fix_token_case($tokens);
	if ($GLOBALS['fix_builtin_functions_case']) fix_builtin_functions_case($tokens);
	if ($GLOBALS['replace_inline_tabs'])        replace_inline_tabs($tokens);
	if ($GLOBALS['replace_phptags'])            replace_phptags($tokens);
	if ($GLOBALS['replace_shell_comments'])     replace_shell_comments($tokens);
	if ($GLOBALS['fix_statement_brackets'])     fix_statement_brackets($tokens);
	if ($GLOBALS['fix_separation_whitespace'])  fix_separation_whitespace($tokens);
	if ($GLOBALS['fix_comma_space'])            fix_comma_space($tokens);
	if ($GLOBALS['add_operator_space'])         add_operator_space($tokens);
	if ($GLOBALS['fix_round_bracket_space'])    fix_round_bracket_space($tokens);

	// PhpDocumentor
	if ($GLOBALS['add_doctags']) {
		list($usestags, $paramtags, $returntags) = collect_doctags($tokens);
		//print_r($usestags);
		//print_r($paramtags);
		//print_r($returntags);
	}
	if ($GLOBALS['add_file_docblock'])      add_file_docblock($tokens);
	if ($GLOBALS['add_function_docblocks']) add_function_docblocks($tokens);
	if ($GLOBALS['add_doctags']) {
		/** @noinspection PhpUndefinedVariableInspection */
		add_doctags($tokens, $usestags, $paramtags, $returntags, $GLOBALS['seetags']);
	}
	if ($GLOBALS['fix_docblock_format']) fix_docblock_format($tokens);
	if ($GLOBALS['fix_docblock_space'])  fix_docblock_space($tokens);

	if ($GLOBALS['add_blank_lines']) add_blank_lines($tokens);

	// Indenting
	if ($GLOBALS['indent']) {
		indent($tokens);
		strip_closetag_indenting($tokens);
	}

	$source = combine_tokens($tokens);

	// Strip trailing whitespace
	$source = preg_replace("/[ \t]+\n/", "\n", $source);

	if ( substr($source, -1)!="\n" ) {
		// Add one line break at the end of the file
		// http://pear.php.net/manual/en/standards.file.php
		$source .= "\n";
	} else {
		// Strip empty lines at the end of the file
		while ( substr($source, -2)=="\n\n" ) $source = substr($source, 0, -1);
	}

	return $source;
}


/**
 * Replacement for broken array_splice() in PHP 7
 * https://bugs.php.net/bug.php?id=70471
 *
 * Works in PHP 5 and 7 as array_splice() of PHP 5.
 *
 * @param array   $input       (reference)
 * @param integer $offset
 * @param integer $length      (optional)
 * @param array   $replacement (optional)
 * @return array
 */
function array_splice_fixed(array &$input, $offset, $length=null, array $replacement=array()) {
	if ($offset < 0) $offset = max(0, count($input) + $offset);
	$left = array_slice($input, 0, $offset);
	if (is_null($length)) {
		$extracted = array_slice($input, $offset);
		$input = array_merge($left, $replacement);
	} else {
		$rest = array_slice($input, $offset);
		if ($length < 0) $length = max(0, count($rest) + $length);
		$extracted = array_slice($rest, 0, $length);
		$input = array_merge($left, $replacement, array_slice($rest, $length));
	}
	return $extracted;
}


//////////////// TOKEN FUNCTIONS ///////////////////


/**
 * Return the text part of a token
 *
 * @param mixed   $token
 * @return string
 */
function token_text($token) {
	if (is_string($token)) return $token;
	return $token[1];
}


/**
 * Print all tokens
 *
 * @param array   $tokens
 */
function print_tokens($tokens) {
	foreach ( $tokens as $token ) {
		if (is_string($token)) {
			display($token."\n");
		} else {
			list($id, $text) = $token;
			display(token_name($id)." ".addcslashes($text, "\0..\40!@\@\177..\377")."\n");
		}
	}
}


/**
 * Wrapper for token_get_all(), because there is new mysterious index 2 ...
 *
 * @param string  $source (reference)
 * @return array
 */
function get_tokens(&$source) {
	$tokens = token_get_all($source);
	foreach ( $tokens as &$token ) {
		if (isset($token[2])) unset($token[2]);
	}
	return $tokens;
}


/**
 * Combine the tokens to the source code
 *
 * @param array   $tokens
 * @return string
 */
function combine_tokens($tokens) {
	$out = "";
	foreach ( $tokens as $key => $token ) {
		if (is_string($token)) {
			$out .= $token;
		} else {
			$out .= $token[1];
		}
	}
	return $out;
}


/**
 * Display a possible syntax error
 *
 * @param array   $tokens
 * @param integer $key
 * @param string  $message (optional)
 */
function possible_syntax_error($tokens, $key, $message="") {
	display("Possible syntax error detected");
	if ($message) display(" (".$message.")");
	display(":\n");
	display(combine_tokens(array_slice($tokens, max(0, $key-5), 10))."\n");
}


/**
 * Remove whitespace from the beginning of a token array
 *
 * @param array   $tokens (reference)
 */
function tokens_ltrim(&$tokens) {
	while (
		isset($tokens[0][0]) and
		$tokens[0][0] === T_WHITESPACE
	) {
		array_splice_fixed($tokens, 0, 1);
	}
}


/**
 * Remove whitespace from the end of a token array
 *
 * @param array   $tokens (reference)
 */
function tokens_rtrim(&$tokens) {
	while (
		isset($tokens[$k=count($tokens)-1][0]) and
		$tokens[$k][0] === T_WHITESPACE
	) {
		array_splice_fixed($tokens, -1);
	}
}


/**
 * Remove all whitespace
 *
 * @param array   $tokens (reference)
 */
function strip_whitespace(&$tokens) {
	foreach ( $tokens as $key => $token ) {
		if (
			isset($token[0]) and
			$token[0] === T_WHITESPACE
		) {
			unset($tokens[$key]);
		}
	}
	$tokens = array_values($tokens);
}


/**
 * Get the argument of a statement
 *
 * @param array   $tokens (reference)
 * @param integer $key    Key of the token of the command for which we want the argument
 * @return array
 */
function get_argument_tokens(&$tokens, $key) {

	$tokens_arg = array();

	$round_braces_count = 0;
	$curly_braces_count = 0;

	++$key;
	while ( isset($tokens[$key]) ) {
		$token = &$tokens[$key];

		if (is_string($token)) {
			if ($token === ";") break;
		} else {
			if ($token[0] === T_CLOSE_TAG) break;
		}

		if       ($token === "(") {
			++$round_braces_count;
		} elseif ($token === ")") {
			--$round_braces_count;
		} elseif (
			$token === "{" or (
				is_array($token) and (
					$token[0] === T_CURLY_OPEN or
					$token[0] === T_DOLLAR_OPEN_CURLY_BRACES
				)
			)
		) {
			++$curly_braces_count;
		} elseif ($token === "}") {
			--$curly_braces_count;
		}

		if ( $round_braces_count < 0 or $round_braces_count < 0 ) break;

		$tokens_arg[] = $token;

		++$key;
	}

	return $tokens_arg;
}


//////////////// FORMATTING FUNCTIONS ///////////////////


/**
 * Check for some tokens which must not be touched
 *
 * @param array   $token (reference)
 * @return boolean
 */
function token_is_taboo(&$token) {
	return (
		// Do not touch HTML content
		$token[0] === T_INLINE_HTML or
		$token[0] === T_CLOSE_TAG or
		// Do not touch the content of strings
		$token[0] === T_CONSTANT_ENCAPSED_STRING or
		$token[0] === T_ENCAPSED_AND_WHITESPACE or
		// Do not touch the content of multiline comments
		($token[0] === T_COMMENT and substr($token[1], 0, 2) === "/*")
	);
}


/**
 * Convert commands to lower case
 *
 * @param array   $tokens (reference)
 */
function fix_token_case(&$tokens) {

	static $lower_case_tokens = array(
		T_ABSTRACT,
		T_ARRAY,
		T_ARRAY_CAST,
		T_AS,
		T_BOOL_CAST,
		T_BREAK,
		T_CASE,
		T_CATCH,
		T_CLASS,
		T_CLONE,
		T_CONST,
		T_CONTINUE,
		T_DECLARE,
		T_DEFAULT,
		T_DO,
		T_DOUBLE_CAST,
		T_ECHO,
		T_ELSE,
		T_ELSEIF,
		T_EMPTY,
		T_ENDDECLARE,
		T_ENDFOR,
		T_ENDFOREACH,
		T_ENDIF,
		T_ENDSWITCH,
		T_ENDWHILE,
		T_EVAL,
		T_EXIT,
		T_EXTENDS,
		T_FINAL,
		T_FOR,
		T_FOREACH,
		T_FUNCTION,
		T_GLOBAL,
		T_IF,
		T_IMPLEMENTS,
		T_INCLUDE,
		T_INCLUDE_ONCE,
		T_INSTANCEOF,
		T_INT_CAST,
		T_INTERFACE,
		T_ISSET,
		T_LIST,
		T_LOGICAL_AND,
		T_LOGICAL_OR,
		T_LOGICAL_XOR,
		T_NEW,
		T_OBJECT_CAST,
		T_PRINT,
		T_PRIVATE,
		T_PUBLIC,
		T_PROTECTED,
		T_REQUIRE,
		T_REQUIRE_ONCE,
		T_RETURN,
		T_STATIC,
		T_STRING_CAST,
		T_SWITCH,
		T_THROW,
		T_TRY,
		T_UNSET,
		T_UNSET_CAST,
		T_VAR,
		T_WHILE
	);

	foreach ( $tokens as &$token ) {
		if (is_string($token)) continue;
		if ($token[1] === strtolower($token[1])) continue;
		if (in_array($token[0], $lower_case_tokens)) {
			$token[1] = strtolower($token[1]);
		}
	}

}


/**
 * Convert builtin functions to lower case
 *
 * @param array   $tokens (reference)
 */
function fix_builtin_functions_case(&$tokens) {

	static $defined_internal_functions = false;
	if ($defined_internal_functions === false) {
		$defined_functions = get_defined_functions();
		$defined_internal_functions = $defined_functions['internal'];
	}

	foreach ( $tokens as $key => &$token ) {

		if (
			is_string($token) or
			$token[0] !== T_STRING or
			!isset($tokens[$key+2]) or
			// Ignore object methods
			(is_array($tokens[$key-1]) and $tokens[$key-1][0] === T_OBJECT_OPERATOR)
		) continue;

		if (
			$tokens[$key+1] === "("
		) {
			$lowercase = strtolower($token[1]);
			if (
				$token[1] !== $lowercase and
				in_array($lowercase, $defined_internal_functions)
			) {
				$token[1] = $lowercase;
			}
		} elseif (
			$tokens[$key+2] === "(" and
			is_array($tokens[$key+1]) and $tokens[$key+1][0] === T_WHITESPACE
		) {
			if (
				in_array(strtolower($token[1]), $defined_internal_functions)
			) {
				$token[1] = strtolower($token[1]);
				// Remove whitespace between function name and opening round bracket
				unset($tokens[$key+1]);
			}
		}

	}

	$tokens = array_values($tokens);
}


/**
 * Replace inline tabs with spaces
 *
 * @param array   $tokens (reference)
 */
function replace_inline_tabs(&$tokens) {

	foreach ( $tokens as &$token ) {

		if ( is_string($token) ) {
			$text =& $token;
		} else {
			if (token_is_taboo($token)) continue;
			$text =& $token[1];
		}

		// Replace one tab with one space
		$text = str_replace("\t", " ", $text);

	}

}


/**
 * Replace PHP-Open-Tags with consistent tags
 *
 * @param array   $tokens (reference)
 */
function replace_phptags(&$tokens) {

	foreach ( $tokens as $key => &$token ) {
		if (is_string($token)) continue;

		switch ($token[0]) {
		case T_OPEN_TAG:

			// The open tag is already the right one
			if ( rtrim($token[1]) == $GLOBALS['open_tag'] ) continue;

			// Collect following whitespace
			preg_match("/\s*$/", $token[1], $matches);
			$whitespace = $matches[0];
			if ( $tokens[$key+1][0] === T_WHITESPACE ) {
				$whitespace .= $tokens[$key+1][1];
				array_splice_fixed($tokens, $key+1, 1);
			}

			if ($GLOBALS['open_tag']=="<?") {

				// Short open tags have the following whitespace in a seperate token
				array_splice_fixed($tokens, $key, 1, array(
						array(T_OPEN_TAG, $GLOBALS['open_tag']),
						array(T_WHITESPACE, $whitespace)
					));

			} else {

				// Long open tags have the following whitespace included in the token string
				switch (strlen($whitespace)) {
				case 0:
					// Add an additional space if no whitespace is found
					$whitespace = " ";
				case 1:
					// Use the one found space or newline
					$tokens[$key][1] = $GLOBALS['open_tag'].$whitespace;
					break;
				default:
					// Use the first space or newline for the open tag and append the rest of the whitespace as a seperate token
					array_splice_fixed($tokens, $key, 1, array(
							array(T_OPEN_TAG, $GLOBALS['open_tag'].substr($whitespace, 0, 1)),
							array(T_WHITESPACE, substr($whitespace, 1))
						));
				}

			}

			break;
		case T_OPEN_TAG_WITH_ECHO:

			// If we use short tags we also accept the echo tags
			if ($GLOBALS['open_tag']=="<?" or $GLOBALS['keep_open_echo_tags']) continue;

			if ( $tokens[$key+1][0] === T_WHITESPACE ) {
				// If there is already whitespace following we only replace the open tag
				array_splice_fixed($tokens, $key, 1, array(
						array(T_OPEN_TAG, $GLOBALS['open_tag']." "),
						array(T_ECHO, "echo")
					));
			} else {
				// If there is no whitespace following we add one space
				array_splice_fixed($tokens, $key, 1, array(
						array(T_OPEN_TAG, $GLOBALS['open_tag']." "),
						array(T_ECHO, "echo"),
						array(T_WHITESPACE, " ")
					));
			}

		}

	}

}


/**
 * Replace shell style comments with C style comments
 *
 * http://pear.php.net/manual/en/standards.comments.php
 *
 * @param array   $tokens (reference)
 */
function replace_shell_comments(&$tokens) {

	foreach ( $tokens as &$token ) {
		if (is_string($token)) continue;
		if (
			$token[0] === T_COMMENT and
			substr($token[1], 0, 1) === "#"
		) {
			$token[1] = "//".substr($token[1], 1);
		}
	}

}


/**
 * Enforce statements without brackets and fixes whitespace
 *
 * http://pear.php.net/manual/en/standards.including.php
 *
 * @param array   $tokens (reference)
 */
function fix_statement_brackets(&$tokens) {

	static $statement_tokens = array(
		T_INCLUDE,
		T_INCLUDE_ONCE,
		T_REQUIRE,
		T_REQUIRE_ONCE,
		T_RETURN,
		T_BREAK,
		T_CONTINUE,
		T_ECHO
	);

	foreach ( $tokens as $key => &$token ) {

		if ( is_string($token) or !in_array($token[0], $statement_tokens) ) continue;

		$tokens_arg = get_argument_tokens($tokens, $key);
		$tokens_arg_orig = $tokens_arg;

		tokens_ltrim($tokens_arg);

		if ( !count($tokens_arg) or $tokens_arg[0] !== "(" ) continue;

		tokens_rtrim($tokens_arg);

		// Check if the opening bracket has a matching one at the end of the expression
		$round_braces_count = 0;
		foreach ( $tokens_arg as $k => $t ) {
			if (is_string($t)) {
				if     ($t === "(") ++$round_braces_count;
				elseif ($t === ")") --$round_braces_count;
				else continue;
				// Check if the expression begins without a bracket or if the bracket was closed before the end of the expression was reached
				if ( $round_braces_count == 0 and $k != count($tokens_arg)-1 ) {
					continue 2;
				}
				if ( $round_braces_count < 0 ) {
					possible_syntax_error($tokens, $key, "Closing round bracket found which has not been opened");
					continue 2;
				}
			} else {
				// Do not touch multiline expressions
				if ($t[0] === T_WHITESPACE and strpos($t[1], "\n")!==false) {
					continue 2;
				}
			}
		}
		// Detect missing brackets
		if ($round_braces_count != 0) {
			possible_syntax_error($tokens, $key, "Round bracket opened but no matching closing bracket found");
			continue;
		}

		// Remove the outermost brackets
		$tokens_arg = array_slice($tokens_arg, 1, -1);

		tokens_ltrim($tokens_arg);
		tokens_rtrim($tokens_arg);

		// Add one space between the command and the argument if the argument is not empty
		if ($tokens_arg) {
			array_unshift($tokens_arg, array(T_WHITESPACE, " "));
		}

		array_splice_fixed($tokens, $key+1, count($tokens_arg_orig), $tokens_arg);

	}

}


/**
 * Fixe whitespace between commands and braces
 *
 * @param array   $tokens (reference)
 */
function fix_separation_whitespace(&$tokens) {

	$control_structure = false;

	foreach ( $tokens as $key => &$token ) {
		if (is_string($token)) {

			// Exactly 1 space or a newline between closing round bracket and opening curly bracket
			if ( $tokens[$key] === ")" ) {
				if (
					isset($tokens[$key+1]) and $tokens[$key+1] === "{"
				) {
					// Insert an additional space or newline before the bracket
					array_splice_fixed($tokens, $key+1, 0, array(
							array(T_WHITESPACE, separation_whitespace($control_structure))
						));
				} elseif (
					isset($tokens[$key+1][0]) and $tokens[$key+1][0] === T_WHITESPACE and
					isset($tokens[$key+2]) and $tokens[$key+2] === "{"
				) {
					// Set the existing whitespace before the bracket to exactly one space or newline
					$tokens[$key+1][1] = separation_whitespace($control_structure);
				}
			}

		} else {

			switch ($token[0]) {
			case T_CLASS:
				// Class definition
				if (
					isset($tokens[$key+1][0]) and $tokens[$key+1][0] === T_WHITESPACE and
					isset($tokens[$key+2][0]) and $tokens[$key+2][0] === T_STRING
				) {
					// Exactly 1 space between 'class' and the class name
					$tokens[$key+1][1] = " ";
					// Exactly 1 space between the class name and the opening curly bracket
					if ( $tokens[$key+3] === "{" ) {
						// Insert an additional space or newline before the bracket
						array_splice_fixed($tokens, $key+3, 0, array(
								array(T_WHITESPACE, separation_whitespace(T_CLASS))
							));
					} elseif (
						isset($tokens[$key+3][0]) and $tokens[$key+3][0] === T_WHITESPACE and
						isset($tokens[$key+4]) and $tokens[$key+4] === "{"
					) {
						// Set the existing whitespace before the bracket to exactly one space or a newline
						$tokens[$key+3][1] = separation_whitespace(T_CLASS);
					}
				}
				break;
			case T_FUNCTION:
				// Function definition
				if (
					isset($tokens[$key+1][0]) and $tokens[$key+1][0] === T_WHITESPACE and
					isset($tokens[$key+2][0]) and $tokens[$key+2][0] === T_STRING
				) {
					// Exactly 1 Space between 'function' and the function name
					$tokens[$key+1][1] = " ";
					// No whitespace between function name and opening round bracket
					if ( isset($tokens[$key+3][0]) and $tokens[$key+3][0] === T_WHITESPACE ) {
						// Remove the whitespace
						array_splice_fixed($tokens, $key+3, 1);
					}
				}
				break;
			case T_IF:
			case T_ELSEIF:
			case T_FOR:
			case T_FOREACH:
			case T_WHILE:
			case T_SWITCH:
				// At least 1 space between a statement and a opening round bracket
				if ( $tokens[$key+1] === "(" ) {
					// Insert an additional space or newline before the bracket
					array_splice_fixed($tokens, $key+1, 0, array(
							array(T_WHITESPACE, separation_whitespace(T_SWITCH)),
						));
				}
				break;
			case T_ELSE:
			case T_DO:
				// Exactly 1 space between a command and a opening curly bracket
				if ( $tokens[$key+1] === "{" ) {
					// Insert an additional space or newline before the bracket
					array_splice_fixed($tokens, $key+1, 0, array(
							array(T_WHITESPACE, separation_whitespace(T_DO)),
						));
				} elseif (
					isset($tokens[$key+1][0]) and $tokens[$key+1][0] === T_WHITESPACE and
					isset($tokens[$key+2]) and $tokens[$key+2] === "{"
				) {
					// Set the existing whitespace before the bracket to exactly one space or a newline
					$tokens[$key+1][1] = separation_whitespace(T_DO);
				}
				break;
			default:
				// Do not set $control_structure if the token is no control structure
				continue 2;
			}

			$control_structure = $token[0];

		}

	}

}


/**
 * Whitespace before an opening curly bracket depending on the control structure
 *
 * @param integer $control_structure token of the control structure
 * @return string
 */
function separation_whitespace($control_structure) {
	if (
		$GLOBALS['curly_brace_newline']===true or (
			is_array($GLOBALS['curly_brace_newline']) and
			in_array($control_structure, $GLOBALS['curly_brace_newline'])
		)
	) return "\n";
	return " ";
}


/**
 * Add one space after an opening and before a closing round bracket
 *
 * @param array   $tokens (reference)
 */
function fix_round_bracket_space(&$tokens) {

	foreach ($tokens as $key => &$token) {
		if (!is_string($token)) continue;
		if (
			// If the current token is an opening round bracket...
			$token === "(" and
			// ...and the next token is no whitespace
			!( isset($tokens[$key+1][0]) and $tokens[$key+1][0] === T_WHITESPACE ) and
			// ...and the next token is not a closing round bracket
			!( isset($tokens[$key+1][0]) and $tokens[$key+1][0] === ')' )
		) {
			// Insert one space
			array_splice_fixed($tokens, $key+1, 0, array(
					array(T_WHITESPACE, " ")
				));
		} elseif (
			// If the current token is an end round bracket...
			$token === ")" and
			// ...and the previous token is no whitespace
			!( isset($tokens[$key-1][0]) and $tokens[$key-1][0] === T_WHITESPACE ) and
			// ...and the previous token is not an opening round bracket
			!( isset($tokens[$key-1][0]) and $tokens[$key-1][0] === '(' )
		) {
			// Insert one space
			array_splice_fixed($tokens, $key, 0, array(
					array(T_WHITESPACE, " ")
				));
		}
	}

}


/**
 * Add one space after a comma
 *
 * @param array   $tokens (reference)
 */
function fix_comma_space(&$tokens) {

	foreach ( $tokens as $key => &$token ) {
		if (!is_string($token)) continue;
		if (
			// If the current token ends with a comma...
			substr($token, -1) === "," and
			// ...and the next token is no whitespace
			!(isset($tokens[$key+1][0]) and $tokens[$key+1][0] === T_WHITESPACE)
		) {
			// Insert one space
			array_splice_fixed($tokens, $key+1, 0, array(
					array(T_WHITESPACE, " ")
				));
		}
	}

}


/**
 * Add one space before and after some operators
 *
 * @param array   $tokens (reference)
 */
function add_operator_space(&$tokens) {

	// Only operators, which don't require the spaces around
	static $operators = array(
		// assignment
		"=",
		array(T_PLUS_EQUAL,  "+="),
		array(T_MINUS_EQUAL, "-="),
		array(T_MUL_EQUAL,   "*="),
		array(T_DIV_EQUAL,   "/="),
		array(T_MOD_EQUAL,   "%="),
		array(T_AND_EQUAL,   "&="),
		array(T_OR_EQUAL,    "|="),
		array(T_XOR_EQUAL,   "^="),
		// comparison
		array(T_IS_EQUAL,         "=="),
		array(T_IS_IDENTICAL,     "==="),
		array(T_IS_NOT_EQUAL,     "!="),
		array(T_IS_NOT_EQUAL,     "<>"),
		array(T_IS_NOT_IDENTICAL, "!=="),
		"<",
		">",
		array(T_IS_SMALLER_OR_EQUAL, "<="),
		array(T_IS_GREATER_OR_EQUAL, ">="),
		// logical
		"!",
		array(T_BOOLEAN_AND, "&&"),
		array(T_BOOLEAN_OR,  "||"),
		// string
		".",
		array(T_CONCAT_EQUAL, ".="),
	);

	foreach ( $tokens as $key => &$token ) {
		if ( in_array($token, $operators) ) {

			if (
				// The next token is no whitespace
				!(isset($tokens[$key+1][0]) and $tokens[$key+1][0] === T_WHITESPACE)
			) {
				// Insert one space after
				array_splice_fixed($tokens, $key+1, 0, array(
						array(T_WHITESPACE, " ")
					));
			}

			if (
				// The token before is no whitespace
				!(isset($tokens[$key-1][0]) and $tokens[$key-1][0] === T_WHITESPACE)
			) {
				// Insert one space before
				array_splice_fixed($tokens, $key, 0, array(
						array(T_WHITESPACE, " ")
					));
			}

		}
	}

}


/**
 * Fix the format of a DocBlock
 *
 * @param array   $tokens (reference)
 */
function fix_docblock_format(&$tokens) {

	foreach ( $tokens as $key => &$token ) {

		if ( is_string($token) or $token[0] !== T_DOC_COMMENT ) continue;

		// Don't touch one line docblocks
		if ( strpos($token[1], "\n")===false ) continue;

		$content = trim(strtr($tokens[$key][1], array("/**"=>"", "*/"=>"")));
		$lines_orig = explode("\n", $content);

		$lines = array();
		$comments_started = false;
		$doctags_started = false;
		$last_line = false;
		foreach ( $lines_orig as $line ) {
			$line = trim($line);

			// Strip empty lines
			if ($line=="") continue;

			// Add stars where missing
			if (substr($line, 0, 1)!="*") $line = "* ".$line;
			elseif ($line!="*" and substr($line, 0, 2)!="* ") $line = "* ".substr($line, 1);

			// Strip empty lines at the beginning
			if (!$comments_started) {
				if ($line=="*" and count($lines_orig)>1) continue;
				$comments_started = true;
			}

			// Add empty line before DocTags if missing
			if (substr($line, 0, 3)=="* @" and !$doctags_started) {
				if ($last_line!="*") $lines[] = "*";
				if ($last_line=="/**") $lines[] = "*";
				$doctags_started = true;
			}

			$lines[] = $line;
			$last_line = $line;
		}

		$param_max_type_length = 7;
		$param_max_variable_length = 2;
		while ( $line = current($lines) ) {

			// DocTag format
			if ( preg_match('/^\* @param(\s+([^\s\$]*))?(\s+(&?\$[^\s]+))?(.*)$/', $line, $matches) ) {

				if (!$matches[2]) $matches[2] = "unknown";

				// Restart loop if more space is needed
				$restart = false;
				if ( strlen($matches[2]) > $param_max_type_length ) {
					$param_max_type_length = strlen($matches[2]);
					$restart = true;
				}
				if ( strlen($matches[4]) > $param_max_variable_length ) {
					$param_max_variable_length = strlen($matches[4]);
					$restart = true;
				}
				if ($restart) {
					reset($lines);
					continue;
				}

				$lines[key($lines)] = "* @param "
					.str_pad($matches[2], $param_max_type_length)." "
					.str_pad($matches[4], $param_max_variable_length)." "
					.trim($matches[5]);
			}

			next($lines);
		}

		// Sort DocTags
		mergesort($lines, "sort_doctags_cmp");

		$token[1] = "/**\n".join("\n", $lines)."\n*/";

	}

}


/**
 * Sort an array by values using a user-defined comparison function
 * If two members compare as equal, their order stays unchanged.
 * http://php.net/manual/en/function.usort.php#38827
 *
 * @param array   $array        (reference)
 * @param string  $cmp_function (optional)
 */
function mergesort(&$array, $cmp_function = 'strcmp') {
	// Arrays of size < 2 require no action.
	if (count($array) < 2) return;
	// Split the array in half
	$halfway = count($array) / 2;
	$array1 = array_slice($array, 0, $halfway);
	$array2 = array_slice($array, $halfway);
	// Recurse to sort the two halves
	mergesort($array1, $cmp_function);
	mergesort($array2, $cmp_function);
	// If all of $array1 is <= all of $array2, just append them.
	if (call_user_func($cmp_function, end($array1), $array2[0]) < 1) {
		$array = array_merge($array1, $array2);
		return;
	}
	// Merge the two sorted arrays into a single sorted array
	$array = array();
	$ptr1 = $ptr2 = 0;
	while ($ptr1 < count($array1) && $ptr2 < count($array2)) {
		if (call_user_func($cmp_function, $array1[$ptr1], $array2[$ptr2]) < 1) {
			$array[] = $array1[$ptr1++];
		}
		else {
			$array[] = $array2[$ptr2++];
		}
	}
	// Merge the remainder
	while ($ptr1 < count($array1)) $array[] = $array1[$ptr1++];
	while ($ptr2 < count($array2)) $array[] = $array2[$ptr2++];
	return;
}


/**
 * Comparison function for DocTags
 *
 * @param string  $a
 * @param string  $b
 * @return integer
 */
function sort_doctags_cmp($a, $b) {

	$order = array("* @author ", "* @package ", "* @see ", "* @uses ", "* @param ", "* @return ");

	$rank_a = 0;
	foreach ($order as $index => $begin) {
		if ( substr($a, 0, strlen($begin)) == $begin ) {
			$rank_a = $index + 1;
			break;
		}
	}
	$rank_b = 0;
	foreach ($order as $index => $begin) {
		if ( substr($b, 0, strlen($begin)) == $begin ) {
			$rank_b = $index + 1;
			break;
		}
	}

	if ($rank_a < $rank_b) return -1;
	if ($rank_a > $rank_b) return  1;
	return 0;
}


/**
 * Adjust empty lines after DocBlocks
 *
 * @param array   $tokens (reference)
 */
function fix_docblock_space(&$tokens) {

	$filedocblock = true;

	foreach ( $tokens as $key => &$token ) {

		if ( is_string($token) or $token[0] !== T_DOC_COMMENT ) continue;

		// Don't touch one line docblocks
		if ( strpos($token[1], "\n")===false ) continue;

		if ( $filedocblock ) {

			// Exactly 2 empty lines after the file DocBlock
			if ( $tokens[$key+1][0] === T_WHITESPACE ) {
				$tokens[$key+1][1] = preg_replace("/\n([ \t]*\n)*/", "\n\n\n", $tokens[$key+1][1]);
			}
			$filedocblock = false;

		} else {

			// Delete empty lines after the DocBlock
			if ( $tokens[$key+1][0] === T_WHITESPACE ) {
				$tokens[$key+1][1] = preg_replace("/\n([ \t]*\n)+/", "\n", $tokens[$key+1][1]);
			}

			// Add empty lines before the DocBlock
			if ( $tokens[$key-1][0] === T_WHITESPACE ) {
				$n = 2;
				if ( substr(token_text($tokens[$key-2]), -1) == "\n" ) --$n;
				// At least 2 empty lines before the docblock of a function
				if ( $tokens[$key+2][0] === T_FUNCTION ) ++$n;
				if ( strpos($tokens[$key-1][1], str_repeat("\n", $n)) === false ) {
					$tokens[$key-1][1] = preg_replace("/(\n){1,".$n."}/", str_repeat("\n", $n), $tokens[$key-1][1]);
				}
			}

		}

	}

}


/**
 * Add 2 blank lines after functions and classes
 *
 * @param array   $tokens (reference)
 */
function add_blank_lines(&$tokens) {

	// Level of curly brackets
	$curly_braces_count = 0;

	$curly_brace_opener = array();
	$control_structure = false;

	$heredoc_started = false;

	foreach ($tokens as $key => &$token) {

		// Skip HEREDOC
		if ( $heredoc_started ) {
			if ( isset($token[0]) and $token[0] === T_END_HEREDOC ) {
				$heredoc_started = false;
			}
			continue;
		}

		if (is_array($token)) {

			// Detect beginning of a HEREDOC block
			if ( $token[0] === T_START_HEREDOC ) {
				$heredoc_started = true;
				continue;
			}

			// Remember the type of control structure
			if ( in_array($token[0], array(T_IF, T_ELSEIF, T_WHILE, T_FOR, T_FOREACH, T_SWITCH, T_FUNCTION, T_CLASS)) ) {
				if ( $token[0] === T_FUNCTION and isset($tokens[$key+1]) and $tokens[$key+1] === "(" ) {
					$control_structure = "anonymous_function";
				} else {
					$control_structure = $token[0];
				}
				continue;
			}

		}

		if ($token === "}") {

			if (
				$curly_brace_opener[$curly_braces_count] === T_FUNCTION or
				$curly_brace_opener[$curly_braces_count] === T_CLASS
			) {

				// At least 2 blank lines after a function or class
				if (
					$tokens[$key+1][0] === T_WHITESPACE and
					substr($tokens[$key+1][1], 0, 2) != "\n\n\n"
				) {
					$tokens[$key+1][1] = preg_replace("/^([ \t]*\n){1,3}/", "\n\n\n", $tokens[$key+1][1]);
				}

			}

			--$curly_braces_count;

		} elseif (
			$token === "{" or (
				is_array($token) and (
					$token[0] === T_CURLY_OPEN or
					$token[0] === T_DOLLAR_OPEN_CURLY_BRACES
				)
			)
		) {

			++$curly_braces_count;
			$curly_brace_opener[$curly_braces_count] = $control_structure;

		}

	}

}


/**
 * Indenting
 *
 * @param array   $tokens (reference)
 */
function indent(&$tokens) {

	// Level of curly brackets
	$curly_braces_count = 0;
	// Level of round brackets
	$round_braces_count = 0;
	// Level of square brackets
	$square_braces_count = 0;

	$round_brace_opener = false;
	$round_braces_control = 0;

	// Number of opened control structures without curly brackets inside of a level of curly brackets
	$control_structure = array(0);

	$heredoc_started = false;
	$trinity_started = false;

	foreach ( $tokens as $key => &$token ) {

		// Skip HEREDOC
		if ( $heredoc_started ) {
			if ( isset($token[0]) and $token[0] === T_END_HEREDOC ) {
				$heredoc_started = false;
			}
			continue;
		}

		// Detect beginning of a HEREDOC block
		if ( isset($token[0]) and $token[0] === T_START_HEREDOC ) {
			$heredoc_started = true;
			continue;
		}

		// The closing bracket itself has to be not indented again, so we decrease the brackets count before we reach the bracket.
		if (isset($tokens[$key+1])) {
			if (is_string($tokens[$key+1])) {
				if (
					is_string($token) or
					$token[0] !== T_WHITESPACE or
					strpos($token[1], "\n")!==false
				) {
					if     ($tokens[$key+1] === "}") --$curly_braces_count;
					elseif ($tokens[$key+1] === ")") --$round_braces_count;
					elseif ($tokens[$key+1] === "]") --$square_braces_count;
				}
			} else {
				if (
					// If the next token is a T_WHITESPACE without a \n, we have to look at the one after the next.
					isset($tokens[$key+2]) and
					$tokens[$key+1][0] === T_WHITESPACE and
					strpos($tokens[$key+1][1], "\n")===false
				) {
					if     ($tokens[$key+2] === "}") --$curly_braces_count;
					elseif ($tokens[$key+2] === ")") --$round_braces_count;
					elseif ($tokens[$key+2] === "]") --$square_braces_count;
				}
			}
		}

		if     ($token === "(") ++$round_braces_control;
		elseif ($token === ")") --$round_braces_control;

		if ( $token === "[" ) {

			++$square_braces_count;

		} elseif ( $token === "(" ) {

			if ($round_braces_control==1) {
				// Remember which command was before the bracket
				$k = $key;
				do {
					--$k;
				} while (
					isset($tokens[$k]) and (
						$tokens[$k][0] === T_WHITESPACE or
						$tokens[$k][0] === T_STRING
					)
				);
				if (is_array($tokens[$k])) {
					$round_brace_opener = $tokens[$k][0];
				} else {
					$round_brace_opener = false;
				}
			}

			++$round_braces_count;

		} elseif (
			(
				$token === ")" and
				$round_braces_control == 0 and
				in_array(
					$round_brace_opener,
					array(T_IF, T_ELSEIF, T_WHILE, T_FOR, T_FOREACH, T_SWITCH, T_FUNCTION)
				)
			) or (
				is_array($token) and (
					(
						$token[0] === T_ELSE and ! (
							// Ignore the "else" in "else if" to avoid indenting twice
							is_array($tokens[$key+1]) and $tokens[$key+1][0] === T_WHITESPACE and
							is_array($tokens[$key+2]) and $tokens[$key+2][0] === T_IF
						)
					) or $token[0] === T_DO
				)
			)
		) {
			// All control stuctures end with a curly bracket, except "else" and "do".
			if (isset($control_structure[$curly_braces_count])) {
				++$control_structure[$curly_braces_count];
			} else {
				$control_structure[$curly_braces_count] = 1;
			}

		} elseif ( $token === ";" or $token === "}" ) {
			// After a command or a set of commands a control structure is closed.
			if (!empty($control_structure[$curly_braces_count])) --$control_structure[$curly_braces_count];

		} else {
			indent_text(
				$tokens,
				$key,
				$curly_braces_count,
				$round_braces_count + $square_braces_count,
				$control_structure,
				(is_array($token) and $token[0] === T_DOC_COMMENT),
				$trinity_started
			);

		}

		if (
			$token === "{" or (
				is_array($token) and (
					$token[0] === T_CURLY_OPEN or
					$token[0] === T_DOLLAR_OPEN_CURLY_BRACES
				)
			)
		) {
			// If a curly bracket occurs, no command without brackets can follow.
			if (!empty($control_structure[$curly_braces_count])) --$control_structure[$curly_braces_count];
			++$curly_braces_count;
			// Inside of the new level of curly brackets it starts with no control structure.
			$control_structure[$curly_braces_count] = 0;
		}

	}

}


/**
 * Indent one token
 *
 * @param array   $tokens             (reference)
 * @param integer $key
 * @param integer $curly_braces_count
 * @param integer $round_braces_count
 * @param array   $control_structure
 * @param boolean $docblock
 * @param boolean $trinity_started    (reference)
 */
function indent_text(&$tokens, $key, $curly_braces_count, $round_braces_count, $control_structure, $docblock, &$trinity_started) {

	if ( is_string($tokens[$key]) ) {
		$text =& $tokens[$key];
		// If there is no line break it is only a inline string, not involved in indenting
		if ( strpos($text, "\n")===false ) return;
	} else {
		$text =& $tokens[$key][1];
		// If there is no line break it is only a inline string, not involved in indenting
		if ( strpos($text, "\n")===false ) return;
		if (token_is_taboo($tokens[$key])) return;
	}

	$indent = $curly_braces_count + $round_braces_count;
	for ( $i=0; $i<=$curly_braces_count; ++$i ) {
		$indent += $control_structure[$i];
	}

	// One indentation level less for "switch ... case ... default"
	if (
		isset($tokens[$key+1]) and
		is_array($tokens[$key+1]) and (
			$tokens[$key+1][0] === T_CASE or
			$tokens[$key+1][0] === T_DEFAULT or (
				isset($tokens[$key+2]) and
				is_array($tokens[$key+2]) and (
					$tokens[$key+2][0] === T_CASE or
					$tokens[$key+2][0] === T_DEFAULT
				) and
				// T_WHITESPACE without \n first
				$tokens[$key+1][0] === T_WHITESPACE and
				strpos($tokens[$key+1][1], "\n")===false
			)
		)
	) --$indent;

	// One indentation level less for an opening curly brace on a seperate line
	if (
		isset($tokens[$key+2]) and (
			$tokens[$key+1] === "{" or (
				is_array($tokens[$key+1]) and (
					$tokens[$key+1][0] === T_CURLY_OPEN or
					$tokens[$key+1][0] === T_DOLLAR_OPEN_CURLY_BRACES
				)
			)
		) and (
			is_array($tokens[$key+2]) and
			$tokens[$key+2][0] === T_WHITESPACE and
			strpos($tokens[$key+2][1], "\n")!==false
		) and (
			// Only if the curly brace belongs to a control structure
			$control_structure[$curly_braces_count] > 0
		)
	) --$indent;

	// One additional indentation level for operators at the beginning or the end of a line
	if (!$round_braces_count) {

		static $operators = array(
			// arithmetic
			"+",
			"-",
			"*",
			"/",
			"%",
			// assignment
			"=",
			array(T_PLUS_EQUAL,  "+="),
			array(T_MINUS_EQUAL, "-="),
			array(T_MUL_EQUAL,   "*="),
			array(T_DIV_EQUAL,   "/="),
			array(T_MOD_EQUAL,   "%="),
			array(T_AND_EQUAL,   "&="),
			array(T_OR_EQUAL,    "|="),
			array(T_XOR_EQUAL,   "^="),
			// bitwise
			"&",
			"|",
			"^",
			array(T_SL, "<<"),
			array(T_SR, ">>"),
			// comparison
			array(T_IS_EQUAL,         "=="),
			array(T_IS_IDENTICAL,     "==="),
			array(T_IS_NOT_EQUAL,     "!="),
			array(T_IS_NOT_EQUAL,     "<>"),
			array(T_IS_NOT_IDENTICAL, "!=="),
			"<",
			">",
			array(T_IS_SMALLER_OR_EQUAL, "<="),
			array(T_IS_GREATER_OR_EQUAL, ">="),
			// logical
			array(T_LOGICAL_AND, "and"),
			array(T_LOGICAL_OR,  "or"),
			array(T_LOGICAL_XOR, "xor"),
			array(T_BOOLEAN_AND, "&&"),
			array(T_BOOLEAN_OR,  "||"),
			// string
			".",
			array(T_CONCAT_EQUAL, ".="),
			// type
			array(T_INSTANCEOF, "instanceof")
		);

		if (
			(isset($tokens[$key+1]) and in_array($tokens[$key+1], $operators)) or
			(isset($tokens[$key-1]) and in_array($tokens[$key-1], $operators))
		) {
			++$indent;
		} elseif (
			(isset($tokens[$key+1]) and $tokens[$key+1] === "?") or
			(isset($tokens[$key-1]) and $tokens[$key-1] === "?")
		) {
			++$indent;
			$trinity_started = true;
		} elseif (
			$trinity_started and (
				(isset($tokens[$key+1]) and $tokens[$key+1] === ":") or
				(isset($tokens[$key-1]) and $tokens[$key-1] === ":")
			)
		) {
			++$indent;
			$trinity_started = false;
		}

	}

	$indent_str = str_repeat($GLOBALS['indent_char'], max($indent, 0));

	// Indent the current token
	$text = preg_replace(
		"/\n[ \t]*/",
		"\n".$indent_str.($docblock?" ":""),
		$text
	);

	// Cut the indenting at the beginning of the next token

	// End of file reached
	if ( !isset($tokens[$key+1]) ) return;

	if ( is_string($tokens[$key+1]) ) {
		$text2 =& $tokens[$key+1];
	} else {
		$text2 =& $tokens[$key+1][1];
	}

	// Remove indenting at beginning of the the next token
	$text2 = preg_replace(
		"/^[ \t]*/",
		"",
		$text2
	);

}


/**
 * Strip indenting before single closing PHP tags
 *
 * @param array   $tokens (reference)
 */
function strip_closetag_indenting(&$tokens) {

	foreach ( $tokens as $key => &$token ) {
		if ( is_string($token) ) continue;
		if (
			// T_CLOSE_TAG with following \n
			$token[0] === T_CLOSE_TAG and
			substr($token[1], -1) === "\n"
		) {
			if (
				// T_WHITESPACE or T_COMMENT before with \n at the end
				isset($tokens[$key-1]) and
				is_array($tokens[$key-1]) and
				($tokens[$key-1][0] === T_WHITESPACE or $tokens[$key-1][0] === T_COMMENT) and
				preg_match("/\n[ \t]*$/", $tokens[$key-1][1])
			) {
				$tokens[$key-1][1] = preg_replace("/\n[ \t]*$/", "\n", $tokens[$key-1][1]);
			} elseif (
				// T_WHITESPACE before without \n
				isset($tokens[$key-1]) and
				is_array($tokens[$key-1]) and
				$tokens[$key-1][0] === T_WHITESPACE and
				strpos($tokens[$key-1][1], "\n")===false and
				// T_WHITESPACE before or T_COMMENT with \n at the end
				isset($tokens[$key-2]) and
				is_array($tokens[$key-2]) and
				($tokens[$key-2][0] === T_WHITESPACE or $tokens[$key-2][0] === T_COMMENT) and
				preg_match("/\n[ \t]*$/", $tokens[$key-2][1])
			) {
				$tokens[$key-1] = "";
				$tokens[$key-2][1] = preg_replace("/\n[ \t]*$/", "\n", $tokens[$key-2][1]);
			}
		}
	}

}


//////////////// PHPDOC FUNCTIONS ///////////////////


/**
 * Get all defined functions
 *
 * Functions inside of curly braces will be ignored.
 *
 * @param string  $content (reference)
 * @return array
 */
function get_functions(&$content) {

	$tokens = get_tokens($content);

	$functions = array();
	$curly_braces_count = 0;
	foreach ( $tokens as $key => &$token ) {

		if (is_string($token)) {
			if     ($token === "{") ++$curly_braces_count;
			elseif ($token === "}") --$curly_braces_count;
		} elseif (
			$token[0] === T_FUNCTION and
			$curly_braces_count === 0 and
			isset($tokens[$key+2]) and
			is_array($tokens[$key+2])
		) {
			$functions[] = $tokens[$key+2][1];
		}

	}

	return $functions;
}


/**
 * Get all defined includes
 *
 * @param array   $seetags (reference)
 * @param string  $content (reference)
 * @param string  $file
 */
function find_includes(&$seetags, &$content, $file) {

	$tokens = get_tokens($content);

	foreach ( $tokens as $key => &$token ) {
		if (is_string($token)) continue;

		if ( !in_array($token[0], array(T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE)) ) continue;

		$t = get_argument_tokens($tokens, $key);
		strip_whitespace($t);

		// Strip round brackets
		if ( $t[0] === "(" and $t[count($t)-1] === ")" ) {
			$t = array_splice_fixed($t, 1, -1);
		}

		if (!$t) {
			possible_syntax_error($tokens, $key, "Missing argument");
			continue;
		}

		if (!is_array($t[0])) continue;

		// Strip leading docroot variable or constant
		if (
			($t[0][0] === T_VARIABLE or $t[0][0] === T_STRING) and
			in_array($t[0][1], $GLOBALS['docrootvars']) and
			$t[1] === "."
		) {
			$t = array_splice_fixed($t, 2);
		}

		if (
			count($t) == 1 and
			$t[0][0] === T_CONSTANT_ENCAPSED_STRING
		) {
			$includedfile = substr($t[0][1], 1, -1);
			$seetags[$includedfile][] = array($file);
			continue;
		}

		if (!$t) {
			possible_syntax_error($tokens, $key, "String concatenator without following string");
		}

	}

}


/**
 * Replace one DocTag in a DocBlock
 *
 * Existing valid DocTags will be used without change
 *
 * @param string  $text    Content of the DocBlock
 * @param string  $tagname Name of the tag
 * @param array   $tags    All tags to be inserted
 * @return string
 */
function add_doctags_to_doc_comment($text, $tagname, $tags) {

	if (!count($tags)) return $text;

	// Replacement for array_unique()
	$tagids = array();
	foreach ( $tags as $key => $tag ) {
		if ( !in_array($tag[0], $tagids) ) {
			$tagids[] = $tag[0];
		} else {
			unset($tags[$key]);
		}
	}

	$oldtags = array();

	$lines = explode("\n", $text);

	$newtext = "";
	foreach ( $lines as $key => $line ) {

		// Add doctags after the last line
		if ( $key == count($lines)-1 ) {
			foreach ( $tags as $tag ) {
				$tagid = $tag[0];
				if ( isset($oldtags[$tagid]) and count($oldtags[$tagid]) ) {

					// Use existing line
					foreach ( $oldtags[$tagid] as $oldtag ) {

						if (
							$tagname == "param" and
							preg_match('/^\s*\*\s+@param\s+([A-Za-z0-9_]+)\s+(\$[A-Za-z0-9_]+)\s*(.*)$/', $oldtag, $matches)
						) {

							// Replace param type if a type hint exists
							if ($tag[1]) $matches[1] = $tag[1];

							// Add comment for optional and reference if not already existing
							if ( substr($matches[3], 0, strlen($tag[2])) != $tag[2] ) {
								$matches[3] = $tag[2]." ".$matches[3];
							}

							$newtext .= "* @param ".$matches[1]." ".$tagid." ".$matches[3]."\n";

						} else {
							// Take old line without changes
							$newtext .= $oldtag."\n";
						}

					}

				} else {

					// Add new line
					switch ($tagname) {
					case "param":
						if (!$tag[1]) $tag[1] = "unknown";
						$newtext .= "* @param ".$tag[1]." ".$tagid." ".$tag[2]."\n";
						break;
					case "uses":
						$newtext .= "* @uses ".$tagid."()\n";
						break;
					case "return":
						$newtext .= "* @return unknown\n";
						break;
					case "author":
						if ($GLOBALS['default_author']) {
							$newtext .= "* @author ".$GLOBALS['default_author']."\n";
						}
						break;
					case "package":
						$newtext .= "* @package ".$GLOBALS['default_package']."\n";
						break;
					case "see":
						$newtext .= "* @see ".$tagid."\n";
						break;
					}

				}

			}
		}

		// Match DocTag
		$regex = '^\s*\*\s+@'.$tagname;
		// Match param tag variable
		if ($tagname=="param") $regex .= '[^\$]*(\$[A-Za-z0-9_]+)';
		if ( preg_match('/'.$regex.'/', $line, $matches) ) {
			if ($tagname=="param") $oldtags[$matches[1]][] = $line;
			else                   $oldtags[""][]          = $line;
		} else {
			// Don't change lines without a DocTag
			$newtext .= $line;
			// Add a line break after every line except the last
			if ( $key != count($lines)-1 ) $newtext.="\n";
		}

	}

	return $newtext;
}


/**
 * Collect the doctags for a function docblock
 *
 * @param array   $tokens (reference)
 * @return array
 */
function collect_doctags(&$tokens) {

	$function_declarations = array();
	$function = "";
	$curly_braces_count = 0;

	$usestags = array();
	$paramtags = array();
	$returntags = array();

	foreach ( $tokens as $key => &$token ) {

		if (is_string($token)) {

			if ($token === "{") {
				++$curly_braces_count;
			} elseif ($token === "}") {
				if (--$curly_braces_count==0) $function = "";
			}

		} else {

			switch ($token[0]) {
			case T_FUNCTION:
				// Find function definitions

				$round_braces_count = 0;

				$k = $key + 1;

				// Anonymous function with no whitespace between function keyword and opening brace
				if ( $tokens[$k] === "(" ) break;

				if ( is_string($tokens[$k]) or $tokens[$k][0] !== T_WHITESPACE ) {
					possible_syntax_error($tokens, $k, "No whitespace found between function keyword and function name");
					break;
				}

				++$k;

				// Anonymous function with whitespace between function keyword and opening brace
				if ( $tokens[$k] === "(" ) break;

				// & before function name
				if ( $tokens[$k] === "&" ) ++$k;

				if ( is_string($tokens[$k]) or $tokens[$k][0] !== T_STRING ) {
					possible_syntax_error($tokens, $k, "No string for function name found");
					break;
				}

				$function = $tokens[$k][1];
				$function_declarations[] = $key;

				// Collect param-doctags
				$k += 2;
				// Area between round brackets
				$reference = false;
				while ( ($tokens[$k] != ")" or $round_braces_count) and $k < count($tokens) ) {
					if ( is_string($tokens[$k]) ) {
						if     ($tokens[$k] === "(") ++$round_braces_count;
						elseif ($tokens[$k] === ")") --$round_braces_count;
						elseif ($tokens[$k] === "&") $reference = true;
					} else {
						$typehint = false;
						if (
							$tokens[$k][0] === T_VARIABLE
						) {
							$typehint = "";
						} elseif (
							$tokens[$k][0] === T_ARRAY and
							isset($tokens[$k+1][0]) and $tokens[$k+1][0] === T_WHITESPACE
						) {
							$typehint = "array";
							if ($tokens[$k+2]==="&") {
								$reference = true;
								$k++;
								if (isset($tokens[$k+2][0]) and $tokens[$k+2][0] === T_WHITESPACE) $k++;
							}
							if (isset($tokens[$k+2][0]) and $tokens[$k+2][0] === T_VARIABLE) {
								$k += 2;
							} else {
								$typehint = false;
							}
						} elseif (
							$tokens[$k][0] === T_STRING and
							isset($tokens[$k+1][0]) and $tokens[$k+1][0] === T_WHITESPACE
						) {
							$typehint = $tokens[$k][1];
							if ($tokens[$k+2]==="&") {
								$reference = true;
								$k++;
								if (isset($tokens[$k+2][0]) and $tokens[$k+2][0] === T_WHITESPACE) $k++;
							}
							if (isset($tokens[$k+2][0]) and $tokens[$k+2][0] === T_VARIABLE) {
								$k += 2;
							} else {
								$typehint = false;
							}
						}
						if ($typehint !== false) {
							$comments = array();
							if (
								(isset($tokens[$k+1]) and $tokens[$k+1] === "=") or (
									isset($tokens[$k+1][0]) and $tokens[$k+1][0] === T_WHITESPACE and
									isset($tokens[$k+2]) and $tokens[$k+2] === "="
								)
							) {
								$comments[] = "optional";
							}
							if ($reference) {
								$comments[] = "reference";
								$reference = false;
							}
							if (count($comments)) {
								$comment = "(".join(", ", $comments).")";
							} else {
								$comment = "";
							}
							$paramtags[$function][] = array($tokens[$k][1], $typehint, $comment);
						}
					}
					++$k;
				}
				break;
			case T_CURLY_OPEN:
			case T_DOLLAR_OPEN_CURLY_BRACES:
				++$curly_braces_count;
				break;
			case T_STRING:
				// Find function calls
				if (
					$tokens[$key+1] === "(" and
					!in_array($key-2, $function_declarations) and
					in_array($token[1], $GLOBALS['functions'])
				) {
					$usestags[$function][] = array($token[1]);
				}
				break;
			case T_RETURN:
				// Find returns
				if (
					$tokens[$key+1] != ";" and
					$tokens[$key+2] != ";"
				) {
					$returntags[$function][] = array("");
				}
				break;
			}

		}

	}

	return array($usestags, $paramtags, $returntags);
}


/**
 * Add file DocBlocks where missing
 *
 * @param array   $tokens (reference)
 */
function add_file_docblock(&$tokens) {

	$default_file_docblock = "/**\n".
		" *".($GLOBALS['file']?" ".$GLOBALS['file']:"")."\n".
		" *\n";
	if ($GLOBALS['default_author']) {
		$default_file_docblock .= " * @author ".$GLOBALS['default_author']."\n";
	}
	$default_file_docblock .= " * @package ".$GLOBALS['default_package']."\n".
		" */";

	if (!isset($tokens[0][0])) {
		// File is empty

		if ($GLOBALS['open_tag']=="<?") {
			// Insert new file docblock
			$tokens = array(
				array(T_OPEN_TAG, "<?"),
				array(T_WHITESPACE, "\n"),
				array(T_DOC_COMMENT, $default_file_docblock),
				array(T_WHITESPACE, "\n")
			);
		} else {
			// Insert new file docblock
			$tokens = array(
				array(T_OPEN_TAG, $GLOBALS['open_tag']."\n"),
				array(T_DOC_COMMENT, $default_file_docblock),
				array(T_WHITESPACE, "\n")
			);
		}

	} elseif ($tokens[0][0]==T_OPEN_TAG) {
		// File begins with PHP

		if ($GLOBALS['open_tag']=="<?") {
			if ( $tokens[1][0] === T_WHITESPACE and $tokens[2][0] === T_DOC_COMMENT ) return;
			// Insert new file docblock after open tag
			array_splice_fixed($tokens, 0, 1, array(
					array(T_OPEN_TAG, "<?"),
					array(T_WHITESPACE, "\n"),
					array(T_DOC_COMMENT, $default_file_docblock),
					array(T_WHITESPACE, "\n")
				));
		} else {
			if ( $tokens[1][0] === T_DOC_COMMENT ) return;
			// Insert new file docblock after open tag
			array_splice_fixed($tokens, 0, 1, array(
					array(T_OPEN_TAG, $GLOBALS['open_tag']."\n"),
					array(T_DOC_COMMENT, $default_file_docblock),
					array(T_WHITESPACE, "\n")
				));
		}

	} elseif ($tokens[0][0]==T_INLINE_HTML) {

		if ( preg_match("/^#!\//", $tokens[0][1]) ) {
			// File begins with "shebang"-line for direct execution

			if ($GLOBALS['open_tag']=="<?") {
				if ( $tokens[2][0] === T_WHITESPACE and $tokens[3][0] === T_DOC_COMMENT ) return;
				// Insert new file docblock after open tag
				array_splice_fixed($tokens, 1, 1, array(
						array(T_OPEN_TAG, "<?"),
						array(T_WHITESPACE, "\n"),
						array(T_DOC_COMMENT, $default_file_docblock),
						array(T_WHITESPACE, "\n")
					));
			} else {
				if ( $tokens[2][0] === T_DOC_COMMENT ) return;
				// Insert new file docblock after open tag
				array_splice_fixed($tokens, 1, 1, array(
						array(T_OPEN_TAG, $GLOBALS['open_tag']."\n"),
						array(T_DOC_COMMENT, $default_file_docblock),
						array(T_WHITESPACE, "\n")
					));
			}

		} else {
			// File begins with HTML

			// Insert new file docblock in open and close tags at the beginning of the file
			if ($GLOBALS['open_tag']=="<?") {
				array_splice_fixed($tokens, 0, 0, array(
						array(T_OPEN_TAG, "<?"),
						array(T_WHITESPACE, "\n"),
						array(T_DOC_COMMENT, $default_file_docblock),
						array(T_WHITESPACE, "\n\n\n"),
						array(T_CLOSE_TAG, "?>\n")
					));
			} else {
				array_splice_fixed($tokens, 0, 0, array(
						array(T_OPEN_TAG, $GLOBALS['open_tag']."\n"),
						array(T_DOC_COMMENT, $default_file_docblock),
						array(T_WHITESPACE, "\n\n\n"),
						array(T_CLOSE_TAG, "?>\n")
					));
			}

		}

	}

}


/**
 * Add funktion DocBlocks where missing
 *
 * @param array   $tokens (reference)
 */
function add_function_docblocks(&$tokens) {

	foreach ( $tokens as $key => &$token ) {

		if ( is_string($token) or $token[0] !== T_FUNCTION ) continue;

		// No DocBlock for anonymous functions
		$k = $key + 1;
		if ( isset($tokens[$k][0]) and $tokens[$k][0]===T_WHITESPACE ) ++$k; // Skip whitespace
		if ( isset($tokens[$k]) and $tokens[$k]==="(" ) continue;

		// Find beginning of the function declaration
		$k = $key;
		while (
			isset($tokens[$k-1]) and
			strpos(token_text($tokens[$k-1]), "\n")===false
		) --$k;

		if (
			!isset($tokens[$k-2]) or
			!is_array($tokens[$k-2]) or
			$tokens[$k-2][0] != T_DOC_COMMENT
		) {

			// Collect old non-phpdoc comments
			$comment = "";
			$replace = 0;
			while (
				isset($tokens[$k-1]) and
				is_array($tokens[$k-1]) and
				$tokens[$k-1][0] === T_COMMENT
			) {
				$comment = " * ".trim(ltrim(trim($tokens[$k-1][1]), "/#"))."\n".$comment;
				--$k;
				++$replace;
			}

			if (!$comment) $comment = " *\n";

			array_splice_fixed($tokens, $k, $replace, array(
					array(T_DOC_COMMENT, "/**\n".
						$comment.
						" */"),
					array(T_WHITESPACE, "\n")
				));

		}

	}

}


/**
 * Add DocTags to file or function DocBlocks
 *
 * @param array   $tokens     (reference)
 * @param array   $usestags
 * @param array   $paramtags
 * @param array   $returntags
 * @param array   $seetags
 */
function add_doctags(&$tokens, $usestags, $paramtags, $returntags, $seetags) {

	$filedocblock = false;

	foreach ( $tokens as $key => &$token ) {

		if (is_string($token)) continue;
		list($id) = $token;
		if ($id != T_DOC_COMMENT) continue;

		$k = $key + 1;
		while ( isset($tokens[$k][0]) and in_array($tokens[$k][0], array(T_WHITESPACE, T_STATIC, T_PUBLIC, T_PROTECTED, T_PRIVATE)) ) ++$k;

		if (
			isset($tokens[$k+2][0]) and
			$tokens[$k][0] === T_FUNCTION and
			$tokens[$k+1][0] === T_WHITESPACE and
			$tokens[$k+2][0] === T_STRING
		) {

			// Function DocBlock
			$f = $tokens[$k+2][1];
			if (isset($paramtags[$f])) {
				$tokens[$key] = array($id, add_doctags_to_doc_comment($tokens[$key][1], "param", $paramtags[$f]));
			}
			if (isset($returntags[$f])) {
				$tokens[$key] = array($id, add_doctags_to_doc_comment($tokens[$key][1], "return", $returntags[$f]));
			}
			if ($GLOBALS['add_usestags'] and isset($usestags[$f])) {
				$tokens[$key] = array($id, add_doctags_to_doc_comment($tokens[$key][1], "uses", $usestags[$f]));
			}

		} elseif ( !$filedocblock ) {

			// File DocBlock
			if ($GLOBALS['add_usestags'] and isset($usestags[""])) {
				$tokens[$key] = array($id, add_doctags_to_doc_comment($tokens[$key][1], "uses", $usestags[""]));
			}
			$tokens[$key] = array($id, add_doctags_to_doc_comment($tokens[$key][1], "author", array(array(""))));
			$tokens[$key] = array($id, add_doctags_to_doc_comment($tokens[$key][1], "package", array(array(""))));
			if (isset($seetags[$GLOBALS['file']])) {
				$tokens[$key] = array($id, add_doctags_to_doc_comment($tokens[$key][1], "see", $seetags[$GLOBALS['file']]));
			}

		}

		$filedocblock = true;

	}

}
