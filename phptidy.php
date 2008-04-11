#!/usr/bin/php
<?
/**
 * phptidy
 *
 * This tool formats the code of PHP scripts, to make them better readable. The
 * used coding standard is mainly inspired by the PEAR Coding Standards.
 *
 * Usage: phptidy.php command [files]
 *
 * Commands:
 *   suffix   Write output into files with suffix .phptidy.php
 *   replace  Replace files and backup original as .phptidybak
 *   tokens   Show source file tokens
 *   diff     Show diff between old and new source
 *   files    Show files that would be processed
 *
 * If no files are supplied on command line, they will be read from the config
 * file.
 *
 * Example for the optional config file '.phptidy-config.php' in the project
 * directory:
 * <code>
 * <?
 * $project_files = array("*.php");
 * $default_author = "Magnus Rosenbaum <phptidy@cmr.cx>";
 * ?>
 * </code>
 *
 * If you supply the config file, the only variable you must set is
 * $project_files. All other variables are optional and have a default value
 * if you omit them. See the default configuration section below for a list of
 * all possible configuration settings.
 *
 * PHP version >= 5.0
 *
 * @todo class DocBlocks
 * @todo repair DocTags
 * @todo check see-Tags
 * @todo make the resulting format more configurable
 * @todo check/convert encoding
 * @todo insert doctags in the right order into the existing ones
 *
 * @copyright 2003-2007 Magnus Rosenbaum
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
 * @version 2.7 (2008-04-11)
 * @author  Magnus Rosenbaum <phptidy@cmr.cx>
 * @package phptidy
 */


error_reporting(E_ALL);


// Default configuration
// You can overwrite all these settings in your configuration files.

// Array with the files in your project. Wildcards for glob() may be used.
// Example: array("*.php", "inc/*.php");
$project_files = array();
// Array with files you want to exclude from the project files
// Example: array("inc/external_lib.php");
$project_files_excludes = array();
// The automatically added author in the phpdoc file docblocks
// Example: "Your Name <you@example.com>"
$default_author = "Magnus Rosenbaum <phptidy@cmr.cx>";
// Name of the automatically added package in the phpdoc file docblocks
// Example: "myproject"
$default_package = "default";
// String used for indenting
// Useful values: "\t" for indenting with tabs,
//                "  " for indenting with two spaces
$indent_char = "\t";
// PHP open tag
// Useful values: "<?", "<?php", "<?PHP"
$open_tag = "<?";
// Docroot-Variables
// Example: array('$docroot', '$GLOBALS[\'docroot\']');
$docrootvars = array();


define('CONFIGFILE', "./.phptidy-config.php");
define('CACHEFILE', "./.phptidy-cache");


// Load config file
if ( file_exists(CONFIGFILE) ) {
	echo "Using configuration file ".CONFIGFILE."\n";
	require CONFIGFILE;
} else {
	echo "Running without configuration file\n";
}

$command = false;
$files = array();

// Files from command line
foreach ( $_SERVER['argv'] as $key => $value ) {
	if ($key==0) continue;
	if ($key==1) {
		$command = $value;
		continue;
	}
	$files[] = $value;
}

if (!in_array($command, array("suffix", "replace", "tokens", "diff", "files"))) {
	trigger_error("Command is missing", E_USER_ERROR);
}

// Files from config file
if (!count($files)) {
	if (!count($project_files)) {
		trigger_error("No files supplied on commandline and also no project files specified in config file", E_USER_ERROR);
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

// Ignore backups and results from phptidy
foreach ( $files as $key => $file ) {
	if (
		substr($file, -12)==".phptidybak~" or
		substr($file, -12)==".phptidy.php"
	) unset($files[$key]);
}

// Check files
foreach ( $files as $key => $file ) {
	if ( !is_readable($file) or !is_file($file) ) {
		trigger_error("File '".$file."' does not exist or is not readable", E_USER_ERROR);
	}
}

// Show files
if ($command=="files") {
	print_r($files);
	exit;
}

// Read cache file
if ( file_exists(CACHEFILE) ) {
	echo "Using cache file ".CACHEFILE."\n";
	$cache = unserialize(file_get_contents(CACHEFILE));
	$cache_orig = $cache;
} else {
	$cache = array(
		'md5sums' => array(),
	);
	$cache_orig = false;
}

// Find functions and includes
echo "Find functions and includes ";
$functions = array();
$seetags = array();
foreach ( $files as $file ) {
	echo ".";
	$source = file_get_contents($file);
	$functions = array_unique(array_merge($functions, get_functions($source)));
	find_includes($seetags, $source, $file);
}
echo "\n";
//print_r($functions);
//print_r($seetags);

$md5sum = md5(serialize($functions).serialize($seetags));
if ( isset($cache['functions_seetags']) and $md5sum == $cache['functions_seetags'] ) {
	// Use cache only if functions and seetags haven't changed
	$use_cache = true;
} else {
	$use_cache = false;
	$cache['functions_seetags'] = $md5sum;
}

echo "Process files\n";
$replaced = 0;
foreach ( $files as $file ) {

	echo " ".$file."\n";
	$source_orig = file_get_contents($file);

	// Cache
	$md5sum = md5($source_orig);
	if ( $use_cache and isset($cache['md5sums'][$file]) and $md5sum == $cache['md5sums'][$file] ) {
		// Original file has not changed, so we don't process it
		continue;
	}

	// Backup
	if ( !copy($file, dirname($file).(dirname($file)?"/":"").".".basename($file).".phptidybak~") ) {
		trigger_error("The file '".$file."' could not be saved", E_USER_ERROR);
	}

	// Process source code
	$source = $source_orig;
	$count = 0;
	do {
		$source_in = $source;
		$source = phptidy($source_in);
		$count++;
		if ($count > 3) {
			echo "Code processed 3 times and still not consistent!\n";
			break;
		}
	} while ( $source != $source_in );

	// Processing has not changed content of file
	if ( $count == 1 ) {
		// Write md5sum of the unchanged file into cache
		$cache['md5sums'][$file] = $md5sum;
		continue;
	}

	// Output
	switch ($command) {
	case "diff":
		file_put_contents("/tmp/tmp.phptidy.php", $source);
		system("diff -u ".$file." /tmp/tmp.phptidy.php 2>&1");
		break;
	case "source":
		echo $source;
		break;
	case "replace":
		file_put_contents($file, $source);
		echo "  replaced.\n";
		$replaced++;
		// Write new md5sum into cache
		$cache['md5sums'][$file] = md5($source);
		break;
	case "suffix":
		$newfile = $file.".phptidy.php";
		file_put_contents($newfile, $source);
		echo "  ".$newfile." saved.\n";
	}

}

if ($command=="replace") {
	if ($replaced) {
		echo "Replaced ".$replaced." files.\n";
	}
	if ($cache != $cache_orig) {
		echo "Write cache file ".CACHEFILE."\n";
		file_put_contents(CACHEFILE, serialize($cache));
	}
}


/////////////////// FUNCTIONS //////////////////////


/**
 * Clean up source code
 *
 * @param string  $source
 * @return string
 */
function phptidy($source) {

	// Replace non-Unix line breaks
	// http://pear.php.net/manual/en/standards.file.php
	// Windows line breaks -> Unix line breaks
	$source = str_replace("\r\n", "\n", $source);
	// Mac line breaks -> Unix line breaks
	$source = str_replace("\r", "\n", $source);

	$tokens = token_get_all($source);

	if ($GLOBALS['command']=="tokens") {
		print_tokens($tokens);
		exit;
	}

	// If you don't want some of the corrections, you can comment them out here:

	// Simple formatting
	fix_token_case($tokens);
	fix_builtin_functions_case($tokens);
	replace_inline_tabs($tokens);
	replace_phptags($tokens);
	replace_shell_comments($tokens);
	fix_statement_brackets($tokens);
	fix_separation_whitespace($tokens);
	fix_comma_space($tokens);

	// PhpDocumentor
	list($usestags, $paramtags, $returntags) = collect_doctags($tokens);
	//print_r($usestags);
	//print_r($paramtags);
	//print_r($returntags);
	add_file_docblock($tokens);
	add_function_docblocks($tokens);
	add_doctags($tokens, $usestags, $paramtags, $returntags, $GLOBALS['seetags']);
	fix_docblock_format($tokens);
	fix_docblock_space($tokens);

	add_blank_lines($tokens);

	// Indenting
	indent($tokens);
	strip_closetag_indenting($tokens);

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


//////////////// TOKEN FUNCTIONS ///////////////////


/**
 * Returns the text part of a token
 *
 * @param mixed   $token
 * @return string
 */
function token_text($token) {
	if (is_string($token)) return $token;
	return $token[1];
}


/**
 * Prints all tokens
 *
 * @param array   $tokens
 */
function print_tokens($tokens) {
	foreach ($tokens as $token) {
		if (is_string($token)) {
			echo $token."\n";
		} else {
			list($id, $text) = $token;
			echo token_name($id)." ".addcslashes($text, "\0..\40!@\@\177..\377")."\n";
		}
	}
}


/**
 * Combine the tokens to the source code
 *
 * @param array   $tokens
 * @return string
 */
function combine_tokens($tokens) {
	$out = "";
	foreach ($tokens as $key => $token) {
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
 */
function possible_syntax_error($tokens, $key) {
	echo "Possible syntax error detected:\n";
	echo combine_tokens(array_slice($tokens, max(0, $key-3), 7))."\n";
}


//////////////// FORMATTING FUNCTIONS ///////////////////


/**
 * Checks for some tokens which must not be touched
 *
 * @param array   $token
 * @return boolean
 */
function token_is_taboo($token) {

	if (
		// Do not touch HTML content
		$token[0]==T_INLINE_HTML or
		$token[0]==T_CLOSE_TAG or
		// Do not touch the content of Strings
		$token[0]==T_CONSTANT_ENCAPSED_STRING or
		$token[0]==T_ENCAPSED_AND_WHITESPACE
	) return true;

	// Do not touch the content of Multiline Comments
	if (
		$token[0]==T_COMMENT and
		substr($token[1], 0, 2)=="/*"
	) return true;

	return false;
}


/**
 * Make commands with Token lowercase
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

	foreach ( $tokens as $key => $token ) {
		if (is_string($token)) continue;
		if (in_array($token[0], $lower_case_tokens)) {
			$tokens[$key][1] = strtolower($token[1]);
		}
	}

}


/**
 * Make builtin functions lowercase
 *
 * @param array   $tokens (reference)
 */
function fix_builtin_functions_case(&$tokens) {

	static $defined_internal_functions = false;
	if ($defined_internal_functions === false) {
		$defined_functions = get_defined_functions();
		$defined_internal_functions = $defined_functions['internal'];
	}

	foreach ( $tokens as $key => $token ) {
		if (is_string($token)) continue;
		if (
			$token[0] === T_STRING and
			in_array(strtolower($token[1]), $defined_internal_functions)
		) {
			if (
				@$tokens[$key+1] === "("
			) {
				$tokens[$key][1] = strtolower($tokens[$key][1]);
			} elseif (
				@$tokens[$key+1][0] === T_WHITESPACE and
				@$tokens[$key+2] === "("
			) {
				unset($tokens[$key+1]);
				$tokens[$key][1] = strtolower($tokens[$key][1]);
			}
		}
	}

	$tokens = array_values($tokens);
}


/**
 * Replace inline tabs
 *
 * @param array   $tokens (reference)
 */
function replace_inline_tabs(&$tokens) {

	foreach ( $tokens as $key => $token ) {

		if ( is_string($tokens[$key]) ) {
			$text =& $tokens[$key];
		} else {
			if (token_is_taboo($tokens[$key])) continue;
			$text =& $tokens[$key][1];
		}

		// Replace all tabs by one space
		$text = str_replace("\t", " ", $text);

	}

}


/**
 * Replace PHP-Open-Tags with consistent Tags
 *
 * @param array   $tokens (reference)
 */
function replace_phptags(&$tokens) {

	reset($tokens);
	while ( list($key, $token) = each($tokens) ) {
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
				array_splice($tokens, $key+1, 1);
			}

			if ($GLOBALS['open_tag']=="<?") {

				// Short open tags have the following whitespace in a seperate token
				array_splice($tokens, $key, 1, array(
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
					array_splice($tokens, $key, 1, array(
							array(T_OPEN_TAG, $GLOBALS['open_tag'].substr($whitespace, 0, 1)),
							array(T_WHITESPACE, substr($whitespace, 1))
						));
				}

			}

			break;
		case T_OPEN_TAG_WITH_ECHO:

			// If we use short tags we also accept the echo tags
			if ($GLOBALS['open_tag']=="<?") continue;

			if ( $tokens[$key+1][0] === T_WHITESPACE ) {
				// If there is already whitespace following we only replace the open tag
				array_splice($tokens, $key, 1, array(
						array(T_OPEN_TAG, $GLOBALS['open_tag']." "),
						array(T_ECHO, "echo")
					));
			} else {
				// If there is no whitespace following we add one space
				array_splice($tokens, $key, 1, array(
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

	foreach ( $tokens as $key => $token ) {
		if (is_string($token)) continue;
		if (
			$token[0] === T_COMMENT and
			substr($token[1], 0, 1) === "#"
		) {
			$tokens[$key][1] = "//".substr($token[1], 1);
		}
	}

}


/**
 * Enforce statements without brackets
 *
 * http://pear.php.net/manual/en/standards.including.php
 *
 * @param array   $tokens (reference)
 */
function fix_statement_brackets(&$tokens) {

	foreach ( $tokens as $key => $token ) {
		if (is_string($token)) continue;

		if ( in_array($token[0], array(T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE, T_RETURN) ) ) {

			$k = $key + 1;

			$space_done = false;
			if ( @$tokens[$k][0] === T_WHITESPACE ) {
				$tokens[$k][1] = " ";
				$space_done = true;
				$k++;
			}

			if ( $tokens[$k] === "(" ) {

				$ke = $k + 1;
				while (
					isset($tokens[$ke]) and
					$tokens[$ke] != ";" and
					$tokens[$ke] != "}" and
					@$tokens[$ke][0] != T_CLOSE_TAG
				) {
					$ke++;
				}

				if ( $tokens[$ke-1] === ")" ) {
					if ($space_done) {
						$tokens[$k] = "";
					} else {
						$tokens[$k] = array(T_WHITESPACE, " ");
					}
					$tokens[$ke-1] = "";
				}

			}

		}

	}

}


/**
 * Fix whitespace between commands and braces
 *
 * @param array   $tokens (reference)
 */
function fix_separation_whitespace(&$tokens) {

	reset($tokens);
	while ( list($key, $token) = each($tokens) ) {
		if (is_string($token)) {

			// Exactly 1 space between closing round bracket and opening curly bracket
			if ( $tokens[$key] === ")" ) {
				if ( @$tokens[$key+1] === "{" ) {
					// Insert an additional space before the bracket
					array_splice($tokens, $key+1, 0, array(
							array(T_WHITESPACE, " ")
						));
				} elseif (
					@$tokens[$key+1][0] === T_WHITESPACE and
					@$tokens[$key+2] === "{"
				) {
					// Set the existing whitespace before the bracket to exactly one space
					$tokens[$key+1][1] = " ";
				}
			}

		} else {

			switch ($token[0]) {
			case T_CLASS:
				// Class definition
				if (
					@$tokens[$key+1][0] === T_WHITESPACE and
					@$tokens[$key+2][0] === T_STRING
				) {
					// Exactly 1 space between 'class' and the class name
					$tokens[$key+1][1] = " ";
					// Exactly 1 space between the class name and the opening curly bracket
					if ( $tokens[$key+3] === "{" ) {
						// Insert an additional space before the bracket
						array_splice($tokens, $key+3, 0, array(
								array(T_WHITESPACE, " ")
							));
					} elseif (
						@$tokens[$key+3][0] === T_WHITESPACE and
						@$tokens[$key+4] === "{"
					) {
						// Set the existing whitespace before the bracket to exactly one space
						$tokens[$key+3][1] = " ";
					}
				}
				break;
			case T_FUNCTION:
				// Function definition
				if (
					@$tokens[$key+1][0] === T_WHITESPACE and
					@$tokens[$key+2][0] === T_STRING
				) {
					// Exactly 1 Space between 'function' and the function name
					$tokens[$key+1][1] = " ";
					// No whitespace between function name and opening round bracket
					if ( @$tokens[$key+3][0] === T_WHITESPACE ) {
						// Remove the whitespace
						array_splice($tokens, $key+3, 1);
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
					// Insert an additional space before the bracket
					array_splice($tokens, $key+1, 0, array(
							array(T_WHITESPACE, " "),
						));
				}
				break;
			case T_ELSE:
			case T_DO:
				// Exactly 1 space between a command and a opening curly bracket
				if ( $tokens[$key+1] === "{" ) {
					// Insert an additional space before the bracket
					array_splice($tokens, $key+1, 0, array(
							array(T_WHITESPACE, " "),
						));
				} elseif (
					@$tokens[$key+1][0] === T_WHITESPACE and
					@$tokens[$key+2] === "{"
				) {
					// Set the existing whitespace before the bracket to exactly one space
					$tokens[$key+1][1] = " ";
				}
				break;
			}

		}

	}

}


/**
 * Add one space after a comma
 *
 * @param array   $tokens (reference)
 */
function fix_comma_space(&$tokens) {

	reset($tokens);
	while ( list($key, $token) = each($tokens) ) {
		if (!is_string($token)) continue;
		if (
			// If the current token ends with a comma...
			substr($token, -1) === "," and
			// ...and the next token is no whitespace
			@$tokens[$key+1][0] != T_WHITESPACE
		) {
			// Insert one space
			array_splice($tokens, $key+1, 0, array(
					array(T_WHITESPACE, " ")
				));
		}
	}

}


/**
 * Fix DocBlock format
 *
 * @param array   $tokens (reference)
 */
function fix_docblock_format(&$tokens) {

	foreach ( $tokens as $key => $token ) {
		if (is_string($token)) continue;

		if ($token[0] === T_DOC_COMMENT) {

			$lines_orig = explode("\n", $tokens[$key][1]);

			$lines = array();
			$comments_started = false;
			$doctags_started = false;
			$last_line = false;
			$param_max_variable_length = 0;
			foreach ( $lines_orig as $line ) {
				$line = trim($line);
				// Strip empty lines
				if ($line=="") continue;
				if ($line!="/**" and $line!="*/") {

					// Add stars where missing
					if (substr($line, 0, 1)!="*") $line = "* ".$line;
					elseif ($line!="*" and substr($line, 0, 2)!="* ") $line = "* ".substr($line, 1);

					// Strip empty lines at the beginning
					if (!$comments_started) {
						if ($line=="*" and count($lines_orig)>3) continue;
						$comments_started = true;
					}

					if (substr($line, 0, 3)=="* @") {

						// Add empty line before DocTags if missing
						if (!$doctags_started) {
							if ($last_line!="*") $lines[] = "*";
							if ($last_line=="/**") $lines[] = "*";
							$doctags_started = true;
						}

						// DocTag format
						if ( preg_match('/^\* @param(\s+[^\s\$]*)?\s+(&?\$[^\s]+)/', $line, $matches) ) {
							$param_max_variable_length = max($param_max_variable_length, strlen($matches[2]));
						}

					}

				}
				$lines[] = $line;
				$last_line = $line;
			}

			foreach ( $lines as $l => $line ) {

				// DocTag format
				if ( preg_match('/^\* @param(\s+([^\s\$]*))?(\s+(&?\$[^\s]+))?(.*)$/', $line, $matches) ) {
					$line = "* @param ";
					if ($matches[2]) $line .= str_pad($matches[2], 7); else $line .= "unknown";
					$line .= " ";
					if ($matches[4]) $line .= str_pad($matches[4], $param_max_variable_length)." ";
					$line .= trim($matches[5]);
					$lines[$l] = $line;
				}

			}

			$tokens[$key][1] = join("\n", $lines);

		}

	}

}


/**
 * Adjust empty lines after DocBlocks
 *
 * @param array   $tokens (reference)
 */
function fix_docblock_space(&$tokens) {

	$filedocblock = true;

	foreach ( $tokens as $key => $token ) {
		if (is_string($token)) continue;

		if ($token[0] !== T_DOC_COMMENT) continue;

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
				if ( substr(token_text($tokens[$key-2]), -1) == "\n" ) $n--;
				// At least 2 empty lines before the docblock of a function
				if ( $tokens[$key+2][0] === T_FUNCTION ) $n++;
				if ( strpos($tokens[$key-1][1], str_repeat("\n", $n)) === false ) {
					$tokens[$key-1][1] = preg_replace("/(\n){1,".$n."}/", str_repeat("\n", $n), $tokens[$key-1][1]);
				}
			}

		}

	}

}


/**
 * Add blank lines after functions
 *
 * @param array   $tokens (reference)
 */
function add_blank_lines(&$tokens) {

	// Level of curly brackets
	$curly_braces_count = 0;

	$curly_brace_opener = array();
	$control_structure = false;

	$heredoc_started = false;

	foreach ($tokens as $key => $token) {

		// Skip heredoc
		if ( $heredoc_started ) {
			if ( isset($token[0]) and $token[0] === T_END_HEREDOC ) {
				$heredoc_started = false;
			}
			continue;
		}
		if ( isset($token[0]) and $token[0] === T_START_HEREDOC ) {
			$heredoc_started = true;
			continue;
		}

		// Remember the type of control structure
		if (
			isset($token[0]) and
			in_array($token[0], array(T_IF, T_ELSEIF, T_WHILE, T_FOR, T_FOREACH, T_SWITCH, T_FUNCTION, T_CLASS))
		) {
			$control_structure = $token[0];
			continue;
		}

		if ($token === "}") {

			if ( in_array($curly_brace_opener[$curly_braces_count], array(T_FUNCTION, T_CLASS)) ) {

				// At least 2 blank lines after a function
				if ( $tokens[$key+1][0] === T_WHITESPACE and substr($tokens[$key+1][1], 0, 2) != "\n\n\n" ) {
					$tokens[$key+1][1] = preg_replace("/^([ \t]*\n){1,3}/", "\n\n\n", $tokens[$key+1][1]);
				}

			}

			$curly_braces_count--;

		} elseif (
			$token === "{" or (
				is_array($token) and (
					$token[0] === T_CURLY_OPEN or
					$token[0] === T_DOLLAR_OPEN_CURLY_BRACES
				)
			)
		) {

			$curly_braces_count++;
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

	$round_brace_opener = false;
	$round_braces_control = 0;

	// Number of opened control structures without curly brackets inside of a level of curly brackets
	$control_structure = array(0);

	$heredoc_started = false;

	foreach ($tokens as $key => $token) {

		// Skip heredoc
		if ( $heredoc_started ) {
			if ( isset($token[0]) and $token[0] === T_END_HEREDOC ) {
				$heredoc_started = false;
			}
			continue;
		}
		if ( isset($token[0]) and $token[0] === T_START_HEREDOC ) {
			$heredoc_started = true;
			continue;
		}

		// The closing bracket itself has to be not indented again, so we decrease the brackets count before we reach the bracket.
		if (isset($tokens[$key+1])) {
			if (is_string($tokens[$key+1])) {
				if (
					!is_string($tokens[$key]) and
					$tokens[$key][0] === T_WHITESPACE and
					!preg_match("/\n/", $tokens[$key][1])
				) {
					// Ignore
				} else {
					if     ($tokens[$key+1] === "}") $curly_braces_count--;
					elseif ($tokens[$key+1] === ")") $round_braces_count--;
				}
			} else {
				if (
					// If the next token is a T_WHITESPACE without a \n, we have to look at the one after the next.
					$tokens[$key+1][0] === T_WHITESPACE and
					!preg_match("/\n/", $tokens[$key+1][1]) and
					isset($tokens[$key+2])
				) {
					if     ($tokens[$key+2] === "}") $curly_braces_count--;
					elseif ($tokens[$key+2] === ")") $round_braces_count--;
				}
			}
		}

		if     ($token === "(") $round_braces_control++;
		elseif ($token === ")") $round_braces_control--;

		if ( $token === "(" ) {

			if ($round_braces_control==1) {
				// Remember which command was before the bracket
				$k = $key;
				do {
					$k--;
					$round_brace_opener = @$tokens[$k][0];
				} while (isset($tokens[$k]) and (!$round_brace_opener or $round_brace_opener===T_WHITESPACE));
			}

			$round_braces_count++;

		} elseif (
			(
				$token === ")" and
				$round_braces_control == 0 and
				in_array(
					$round_brace_opener,
					array(T_IF, T_ELSEIF, T_WHILE, T_FOR, T_FOREACH, T_SWITCH)
				)
			) or (
				is_array($token) and (
					$token[0] === T_ELSE or $token[0] === T_DO
				)
			)
		) {
			// All control stuctures end with a curly bracket, except "else" and "do".
			@$control_structure[$curly_braces_count]++;

		} elseif ( $token === ";" or $token === "}" ) {
			// After a command or a set of commands a control structure is closed.
			if (@$control_structure[$curly_braces_count]) $control_structure[$curly_braces_count]--;

		} else {
			indent_text(
				$tokens,
				$key,
				$curly_braces_count,
				$round_braces_count,
				$control_structure,
				(is_array($token) and $token[0] === T_DOC_COMMENT)
			);

		}

		if (
			$token=="{" or (
				is_array($token) and (
					$token[0] === T_CURLY_OPEN or
					$token[0] === T_DOLLAR_OPEN_CURLY_BRACES
				)
			)
		) {
			// If a curly bracket occurs, no command without brackets can follow.
			if (@$control_structure[$curly_braces_count]) $control_structure[$curly_braces_count]--;
			$curly_braces_count++;
			// Inside of the new level of curly brackets it starts with no control structure.
			$control_structure[$curly_braces_count] = 0;
		}

	}

}


/**
 * Indenting in einem Tokentext
 *
 * @param array   $tokens             (reference)
 * @param integer $key
 * @param integer $curly_braces_count
 * @param integer $round_braces_count
 * @param array   $control_structure
 * @param boolean $docblock
 */
function indent_text( &$tokens, $key, $curly_braces_count, $round_braces_count, $control_structure, $docblock ) {

	if ( is_string($tokens[$key]) ) {
		$text =& $tokens[$key];
	} else {
		if (token_is_taboo($tokens[$key])) return;
		$text =& $tokens[$key][1];
	}

	// If there is no line break it is only a inline string, not involved in indenting
	if ( !preg_match("/\n/", $text) ) return;

	$indent = $curly_braces_count + $round_braces_count;
	for ( $i=0; $i<=$curly_braces_count; $i++ ) {
		$indent += $control_structure[$i];
	}

	// One indentation level less for "switch ... case ... default"
	if (
		isset($tokens[$key+1]) and
		is_array($tokens[$key+1]) and (
			$tokens[$key+1][0] === T_CASE or
			$tokens[$key+1][0] === T_DEFAULT or (
				// zuerst T_WHITESPACE ohne \n
				$tokens[$key+1][0] === T_WHITESPACE and
				!preg_match("/\n/", $tokens[$key+1][1]) and
				isset($tokens[$key+2]) and
				is_array($tokens[$key+2]) and (
					$tokens[$key+2][0] === T_CASE or
					$tokens[$key+2][0] === T_DEFAULT
				)
			)
		)
	) {
		$indent--;
	}

	// One additional indentation level for operators at the beginning or the end of a line
	$operators = array("+", "-", "*", "/", "%", "=", "&", "|", "^", "<", ">", ".");
	if (
		!$round_braces_count and (
			(isset($tokens[$key+1]) and in_array($tokens[$key+1], $operators)) or
			(isset($tokens[$key-1]) and in_array($tokens[$key-1], $operators))
		)
	) {
		$indent++;
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

	foreach ( $tokens as $key => $token ) {
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
				!preg_match("/\n/", $tokens[$key-1][1]) and
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
 * @param string  $content
 * @return array
 */
function get_functions($content) {

	$tokens = token_get_all($content);

	$functions = array();
	$curly_braces_count = 0;
	foreach ($tokens as $key => $token) {

		if (is_string($token)) {
			if     ($token === "{") $curly_braces_count++;
			elseif ($token === "}") $curly_braces_count--;
		} elseif (
			$token[0] === T_FUNCTION and
			!$curly_braces_count and
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
 * @param string  $content
 * @param string  $file
 */
function find_includes(&$seetags, $content, $file) {

	$tokens = token_get_all($content);
	foreach ($tokens as $key => $token) {
		if (is_string($token)) continue;

		if ( in_array($token[0], array(T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE)) ) {

			// Step through the area until the ";" or close tag and strip whitespace
			$t = array();
			$k = $key + 1;
			while (
				$k < count($tokens) and
				$tokens[$k] != ";" and
				@$tokens[$k][0] !== T_CLOSE_TAG
			) {
				if ( @$tokens[$k][0] !== T_WHITESPACE ) {
					$t[] = $tokens[$k];
				}
				$k++;
			}

			if (!$t) {
				possible_syntax_error($tokens, $key);
				continue;
			}

			// Strip round brackets
			if ( $t[0] === "(" and $t[count($t)-1] === ")" ) {
				$t = array_splice($t, 1, -1);
			}

			// Strip leading docroot variable
			if (
				isset($t[0][0]) and
				$t[0][0] === T_VARIABLE and
				in_array($t[0][1], $GLOBALS['docrootvars']) and
				$t[1] === "."
			) {
				$t = array_splice($t, 2);
			}

			if (!$t) {
				possible_syntax_error($tokens, $key);
				continue;
			}

			if (
				count($t) == 1 and
				isset($t[0][0]) and
				$t[0][0] === T_CONSTANT_ENCAPSED_STRING
			) {
				$includedfile = substr($t[0][1], 1, -1);
				$seetags[$includedfile][] = array($file);
			}

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
			foreach ($tags as $tag) {
				$tagid = $tag[0];
				if ( isset($oldtags[$tagid]) and count($oldtags[$tagid]) ) {

					// Use existing line
					foreach ($oldtags[$tagid] as $oldtag) {

						if (
							$tagname == "param" and
							preg_match('/^\s*\*\s+@param\s+([A-Za-z0-9_]+)\s+(\$[A-Za-z0-9_]+)\s+(.*)$/', $oldtag, $matches)
						) {

							// Replace param type if a type hint exists
							if (empty($tag[1])) $tag[1] = $matches[1];

							// Add comment for optional and reference if not already existing
							if (
								isset($tag[2]) and
								substr($matches[3], 0, strlen($tag[2])) != $tag[2]
							) {
								$matches[3] = $tag[2]." ".$matches[3];
							}

							$newtext .= "* @param ".$tag[1]." ".$tag[0]." ".$matches[3]."\n";

						} else {
							// Take old line without changes
							$newtext .= $oldtag."\n";
						}

					}

				} else {

					// Add new line
					switch ($tagname) {
					case "param":
						if (empty($tag[1])) $tag[1] = "unknown";
						$newtext .= "* @param ".$tag[1]." ".$tag[0]." ".@$tag[2]."\n";
						break;
					case "uses":
						$newtext .= "* @uses ".$tag[0]."()\n";
						break;
					case "return":
						$newtext .= "* @return unknown\n";
						break;
					case "author":
						$newtext .= "* @author ".$GLOBALS['default_author']."\n";
						break;
					case "package":
						$newtext .= "* @package ".$GLOBALS['default_package']."\n";
						break;
					case "see":
						$newtext .= "* @see ".$tag[0]."\n";
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
 * Collect doctags for a function docblock
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

	foreach ($tokens as $key => $token) {

		if (is_string($token)) {

			if ($token === "{") {
				$curly_braces_count++;
			} elseif ($token === "}") {
				if (--$curly_braces_count==0) $function = "";
			}

		} else {

			switch ($token[0]) {
			case T_FUNCTION:
				// Find function definitions

				$round_braces_count = 0;

				$k = $key + 1;

				if ( @$tokens[$k][0] !== T_WHITESPACE ) {
					possible_syntax_error($tokens, $k);
					break;
				}

				$k++;

				// & before function name
				if ( $tokens[$k] === "&" ) $k++;

				if ( @$tokens[$k][0] !== T_STRING ) {
					possible_syntax_error($tokens, $k);
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
						if     ($tokens[$k] === "(") $round_braces_count++;
						elseif ($tokens[$k] === ")") $round_braces_count--;
						elseif ($tokens[$k] === "&") $reference = true;
					} else {
						$typehint = false;
						if (
							$tokens[$k][0] === T_VARIABLE
						) {
							$typehint = "";
						} elseif (
							$tokens[$k][0] === T_ARRAY and
							@$tokens[$k+1][0] === T_WHITESPACE and
							@$tokens[$k+2][0] === T_VARIABLE
						) {
							$k += 2;
							$typehint = "array";
						} elseif (
							$tokens[$k][0] === T_STRING and
							@$tokens[$k+1][0] === T_WHITESPACE and
							@$tokens[$k+2][0] === T_VARIABLE
						) {
							$k += 2;
							$typehint = "object";
						}
						if ($typehint !== false) {
							$comments = array();
							if (
								@$tokens[$k+1] === "=" or (
									@$tokens[$k+1][0] === T_WHITESPACE and
									@$tokens[$k+2] === "="
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
					$k++;
				}
				break;
			case T_CURLY_OPEN:
			case T_DOLLAR_OPEN_CURLY_BRACES:
				$curly_braces_count++;
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
		" * ".$GLOBALS['file']."\n".
		" *\n".
		" * @author ".$GLOBALS['default_author']."\n".
		" * @package ".$GLOBALS['default_package']."\n".
		" */";

	// File begins with PHP
	switch ($tokens[0][0]) {
	case T_OPEN_TAG:

		if ($GLOBALS['open_tag']=="<?") {
			if ( $tokens[1][0] === T_WHITESPACE and $tokens[2][0] === T_DOC_COMMENT ) return;
			// Insert new file docblock after open tag
			array_splice($tokens, 0, 1, array(
					array(T_OPEN_TAG, "<?"),
					array(T_WHITESPACE, "\n"),
					array(T_DOC_COMMENT, $default_file_docblock),
					array(T_WHITESPACE, "\n")
				));
		} else {
			if ( $tokens[1][0] === T_DOC_COMMENT ) return;
			// Insert new file docblock after open tag
			array_splice($tokens, 0, 1, array(
					array(T_OPEN_TAG, $GLOBALS['open_tag']."\n"),
					array(T_DOC_COMMENT, $default_file_docblock),
					array(T_WHITESPACE, "\n")
				));
		}

		break;
	case T_INLINE_HTML:

		if ( preg_match("/^#!\//", $tokens[0][1]) ) {
			// File begins with "shebang"-line for direct execution

			if ($GLOBALS['open_tag']=="<?") {
				if ( $tokens[2][0] === T_WHITESPACE and $tokens[3][0] === T_DOC_COMMENT ) return;
				// Insert new file docblock after open tag
				array_splice($tokens, 1, 1, array(
						array(T_OPEN_TAG, "<?"),
						array(T_WHITESPACE, "\n"),
						array(T_DOC_COMMENT, $default_file_docblock),
						array(T_WHITESPACE, "\n")
					));
			} else {
				if ( $tokens[2][0] === T_DOC_COMMENT ) return;
				// Insert new file docblock after open tag
				array_splice($tokens, 1, 1, array(
						array(T_OPEN_TAG, $GLOBALS['open_tag']."\n"),
						array(T_DOC_COMMENT, $default_file_docblock),
						array(T_WHITESPACE, "\n")
					));
			}

		} else {
			// File begins with HTML

			// Insert new file docblock in open and close tags at the beginning of the file
			if ($GLOBALS['open_tag']=="<?") {
				array_splice($tokens, 0, 0, array(
						array(T_OPEN_TAG, "<?"),
						array(T_WHITESPACE, "\n"),
						array(T_DOC_COMMENT, $default_file_docblock),
						array(T_WHITESPACE, "\n\n\n"),
						array(T_CLOSE_TAG, "?>\n")
					));
			} else {
				array_splice($tokens, 0, 0, array(
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

	reset($tokens);
	while ( list($key, $token) = each($tokens) ) {
		if (is_string($token)) continue;

		if ( $token[0] === T_FUNCTION ) {

			// Find beginning of the function declaration
			$k = $key;
			while (
				isset($tokens[$k-1]) and
				strpos(token_text($tokens[$k-1]), "\n")===false
			) $k--;

			if (
				!isset($tokens[$k-2]) or
				!is_array($tokens[$k-2]) or
				$tokens[$k-2][0] != T_DOC_COMMENT
			) {
				array_splice($tokens, $k, 0, array(
						array(T_DOC_COMMENT, "/**\n".
							" *\n".
							" */"),
						array(T_WHITESPACE, "\n")
					));
			}

		}
	}

}


/**
 * Add DocTags to a file or function DocBlocks
 *
 * @param array   $tokens     (reference)
 * @param array   $usetags
 * @param array   $paramtags
 * @param array   $returntags
 * @param array   $seetags
 */
function add_doctags(&$tokens, $usetags, $paramtags, $returntags, $seetags) {

	$filedocblock = false;

	foreach ($tokens as $key => $token) {

		if (is_string($token)) continue;
		list($id, $text) = $token;
		if ($id != T_DOC_COMMENT) continue;

		$k = $key + 1;
		while ( in_array($tokens[$k][0], array(T_WHITESPACE, T_STATIC, T_PUBLIC, T_PROTECTED, T_PRIVATE)) ) $k++;

		if (
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
			if (isset($usestags[$f])) {
				$tokens[$key] = array($id, add_doctags_to_doc_comment($tokens[$key][1], "uses", $usestags[$f]));
			}

		} elseif ( !$filedocblock ) {

			// File DocBlock
			if (isset($usestags[""])) {
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
