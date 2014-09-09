<?php
/**
 * DocBlockGen
 *
 * This class will generate docblock outline for a string
 *
 * File was modified to work with EditPlus 3 using STDIN and STDOUT
 * Features were added during modification:
 *   + Auto-detects function/class visibility
 *   + Auto-detects parameter types based on defaults
 *   + Support for interface, abstract, final, extends, implements and static
 *   + Support for DocBlocking on currently selected text in EditPlus 3
 *   + Support for tabs and spaces for indentation
 *
 * Command Line Example:
 *   + Windows: type source.php | php docblock-gen.php > target.php
 *               ^^  "type" is a command that is required
 *
 * Credit to Sean Coates for getPrototypes function (since modified)
 * http://seancoates.com/fun-with-the-tokenizer
 *
 * Credit to Anthony Gentile for the original PHP-DocBlock-Generator
 * Most of the code here is his
 * https://github.com/agentile/PHP-Docblock-Generator
 *
 * @todo add all proper docblock properties
 * @todo better checking for if docblock already exists
 * @todo docblocking for class properties/variables
 * @todo try to gather even more data for automatic insertion (@return, etc)
 * @todo detect line break types automatically (\r\n or \n)
 * @todo fix bug that prevents DocBlocks from being created on adjacent lines
 *
 * @modified
 * @version 0.85.3
 * @author    Dale Higgs <dale3h@gmail.com>
 * @link      https://github.com/dale3h/docblock-gen/
 */

/**
 * DocBlockGen class
 */
class DocBlockGen {
	private $file_contents;
	private $injected_tag = false;

	const PHP_TAG = "<?php\r\n";

	/**
	 * __construct function
	 *
	 * @access public
	 *
	 * @param string $file_contents
	 *
	 * @return void
	 */
	public function __construct($file_contents) {
		$this->file_contents = $file_contents;

		if (strpos($this->file_contents, '<?') === false) {
			$this->injected_tag = true;
			$this->file_contents = $this::PHP_TAG . $this->file_contents;
		}
	}

	/**
	 * result function
	 * Print output to command line (stdout)
	 *
	 * @access public
	 *
	 * @return string
	 */
	public function result() {
		if ($this->injected_tag && substr($this->file_contents, 0, strlen($this::PHP_TAG)) == $this::PHP_TAG)
			$this->file_contents = substr($this->file_contents, strlen($this::PHP_TAG));

		echo $this->file_contents;
	}

	/**
	 * start function
	 * Begin the docblocking process, using the string
	 *
	 * @access public
	 *
	 * @return void
	 */
	public function start() {
		$this->fileDocBlock();
	}

	/**
	 * fileDocBlock function
	 * Shell method for docblock operations, explodes file, performs docblock methods, impodes.
	 *
	 * @access private
	 *
	 * @return void
	 */
	private function fileDocBlock() {
		list($funcs, $classes, $ifaces) = $this->getPrototypes();

		$this->file_contents = explode("\r\n", $this->file_contents);
		$this->file_contents = $this->docBlock($this->file_contents, $funcs, $classes, $ifaces);
		$this->file_contents = implode("\r\n", $this->file_contents);
	}

	/**
	 * getPrototypes function
	 * This function goes through the tokens to gather the arrays of information we need
	 *
	 * @access private
	 *
	 * @return array
	 */
	private function getPrototypes() {
		$tokens     = token_get_all($this->file_contents);

		$funcs       = array();
		$classes     = array();
		$ifaces      = array();
		$curr_class  = '';
		$curr_iface  = '';
		$class_depth = 0;
		$iface_depth = 0;

		$count = count($tokens);

		for ($i = 0; $i < $count; $i++) {
			if (is_array($tokens[$i]) && $tokens[$i][0] == T_CLASS) {
				$line = $tokens[$i][2];
				++$i; // whitespace;
				$curr_class = $tokens[++$i][1];
				if (!in_array(array('line' => $line, 'name' => $curr_class), $classes)) {
					$classes[] = array('line' => $line, 'name' => $curr_class);
				}
				while ($tokens[++$i] != '{') {}
				++$i;
				$class_depth = 1;
				continue;
			} elseif (is_array($tokens[$i]) && $tokens[$i][0] == T_INTERFACE) {
				$line = $tokens[$i][2];
				++$i; // whitespace;
				$curr_iface = $tokens[++$i][1];
				if (!in_array(array('line' => $line, 'name' => $curr_iface), $ifaces)) {
					$ifaces[] = array('line' => $line, 'name' => $curr_iface);
				}
				while ($tokens[++$i] != '{') {}
				++$i;
				$iface_depth = 1;
				continue;
			} elseif (is_array($tokens[$i]) && $tokens[$i][0] == T_FUNCTION) {
				$next_by_ref = FALSE;
				$this_func = array();

				while ($tokens[++$i] != ')') {
					if (is_array($tokens[$i]) && $tokens[$i][0] != T_WHITESPACE) {
						if (!$this_func) {
							$this_func = array(
								'name' => $tokens[$i][1],
								'class' => $curr_class,
								'line' => $tokens[$i][2],
							);
						} else {
							$this_func['params'][] = array(
								'byRef' => $next_by_ref,
								'name' => $tokens[$i][1],
							);
							$next_by_ref = FALSE;
						}
					} elseif ($tokens[$i] == '&') {
						$next_by_ref = TRUE;
					} elseif ($tokens[$i] == '=') {
						while (!in_array($tokens[++$i], array(')', ','))) {
							if ($tokens[$i][0] != T_WHITESPACE) {
								break;
							}
						}
						$this_func['params'][count($this_func['params']) - 1]['default'] = $tokens[$i][1];
					}
				}
				$funcs[] = $this_func;
			} elseif ($tokens[$i] == '{' || $tokens[$i] == 'T_CURLY_OPEN' || $tokens[$i] == 'T_DOLLAR_OPEN_CURLY_BRACES') {
				++$class_depth;
			} elseif ($tokens[$i] == '}') {
				--$class_depth;
			}

			if ($class_depth == 0) {
				$curr_class = '';
			}
		}

		return array($funcs, $classes, $ifaces);
	}

	/**
	 * docBlock function
	 * Main docblock function, determines if class or function docblocking is need and calls
	 * appropriate subfunction.
	 *
	 * @access private
	 *
	 * @param array $arr
	 * @param array $funcs
	 * @param array $classes
	 * @param array $ifaces
	 *
	 * @return array
	 */
	private function docBlock($arr, $funcs, $classes, $ifaces) {
		$func_lines = array();
		foreach ($funcs as $func) {
			$func_lines[] = $func['line'];
		}

		$class_lines = array();
		foreach ($classes as $class) {
			$class_lines[] = $class['line'];
		}

		$iface_lines = array();
		foreach ($ifaces as $iface) {
			$iface_lines[] = $iface['line'];
		}

		$class_or_func = '';
		$count = count($arr);

		for ($i = 0; $i < $count; $i++) {
			$line = $i + 1;
			$code = $arr[$i];

			if (in_array($line, $class_lines) && !$this->docBlockExists($arr[($i - 1)])) {
				$class_or_func = 'class';
			} elseif (in_array($line, $func_lines) && !$this->docBlockExists($arr[($i - 1)])) {
				$class_or_func = 'func';
			} elseif (in_array($line, $iface_lines) && !$this->docBlockExists($arr[($i - 1)])) {
				$class_or_func = 'iface';
			} else {
				continue;
			}

			if ($class_or_func === 'func') {
				$data = $this->getData($line, $funcs);
			} elseif ($class_or_func === 'class') {
				$data = $this->getData($line, $classes);
			} elseif ($class_or_func === 'iface') {
				$data = $this->getData($line, $ifaces);
			}

			$indent = $this->getStrIndent($code);
			if ($class_or_func === 'func') {
				$doc_block = $this->functionDocBlock($indent, $data, $code);
			} elseif ($class_or_func === 'class') {
				$doc_block = $this->classDocBlock($indent, $data, $code);
			} elseif ($class_or_func === 'iface') {
				$doc_block = $this->interfaceDocBlock($indent, $data, $code);
			}
			$arr[$i] = $doc_block . $arr[$i];
		}
		return $arr;
	}

	/**
	 * getData function
	 * Retrieve method or class information from our arrays
	 *
	 * @access private
	 *
	 * @param string $line
	 * @param array  $arr
	 *
	 * @return mixed
	 */
	private function getData($line, $arr) {
		foreach ($arr as $k => $v) {
			if ($line == $v['line']) {
				return $arr[$k];
			}
		}
		return false;
	}

	/**
	 * docBlockExists function
	 * Primitive check to see if docblock already exists
	 *
	 * @access private
	 *
	 * @param string $line
	 *
	 * @return bool
	 */
	private function docBlockExists($line) {
		// ok we are simply going to check the line above the function and look for */
		// TODO: make this a more accurate check.
		$indent = strlen($this->getStrIndent($line));
		if ($indent > 0) {
			$line = substr($line, ($indent - 1));
		}
		$len = strlen($line);
		if ($len == 0) {
			return false;
		}
		$asterik = false;
		for ($i = 0; $i < $len; $i++) {
			if ($line[$i] == '*') {
				$asterik = true;
			} elseif ($line[$i] == '/' && $asterik == true) {
				return true;
			} else {
				$asterik = false;
			}
		}
		return false;
	}

	/**
	 * functionDocBlock function
	 * DocBlock for function
	 *
	 * @access private
	 *
	 * @param string $indent
	 * @param array  $data
	 * @param string $line
	 *
	 * @return string
	 */
	private function functionDocBlock($indent, $data, $line) {
		$doc_block = "{$indent}/**\r\n";
		$doc_block .= "{$indent} * {$data['name']} function\r\n";
		$doc_block .= "{$indent} *\r\n";

		// Detect function visibility
		if (preg_match('/protected\s[^\(]/', $line) === 1)
			$access = 'protected';
		elseif (preg_match('/private\s[^\(]/', $line) === 1)
			$access = 'private';
		else
			$access = 'public';
		$doc_block .= "{$indent} * @access {$access}\r\n";

		// Detect if function is abstract
		if (preg_match('/abstract\s[^\(]/', $line) === 1)
			$doc_block .= "{$indent} * @abstract\r\n";

		// Detect if function is final
		if (preg_match('/final\s[^\(]/', $line) === 1)
			$doc_block .= "{$indent} * @final\r\n";

		// Detect if function is static
		if (preg_match('/static\s[^\(]/', $line) === 1)
			$doc_block .= "{$indent} * @static\r\n";

		$doc_block .= "{$indent} *\r\n";

		// Detect parameters
		if (isset($data['params'])) {
			$params = array();

			foreach($data['params'] as $func_param) {
				if (isset($func_param['default'])) {
					$default = $func_param['default'];

					if (is_numeric($default)) {
						if ((int)$default == $default) {
							$var_type = 'int';
						} else {
							$var_type = 'float';
						}
					} elseif (strpos($default, '\'') !== false || strpos($default, '"') !== false) {
						$var_type = 'string';
					} elseif (strtolower($default) == 'true' || strtolower($default) == 'false') {
						$var_type = 'bool';
					} elseif (strtolower($default) == 'null') {
						$var_type = 'mixed';
					} elseif (strtolower($default) == 'array') {
						$var_type = 'array';
					} else {
						$var_type = 'mixed';
					}
				} else {
					$var_type = 'mixed';
				}

				$params[] = array('type' => $var_type, 'name' => $func_param['name']);
			}

			if (count($params)) {
				// Gets longest type (so that we can pad with spaces)
				$longest_type = 0;
				foreach ($params as $param) {
					if (strlen($param['type']) > $longest_type)
						$longest_type = strlen($param['type']);
				}

				// Adds each param to $doc_block (pads shorter types)
				foreach ($params as $param) {
					$param['type'] = str_pad($param['type'], $longest_type, ' ', STR_PAD_RIGHT);
					$doc_block .= "{$indent} * @param {$param['type']} {$param['name']}\r\n";
				}

				$doc_block .= "{$indent} *\r\n";
			}
		}

		// Return type
		$doc_block .= "{$indent} * @return void\r\n";

		$doc_block .= "{$indent} */\r\n";

		return $doc_block;
	}

	/**
	 * classDocBlock function
	 * DocBlock for class
	 *
	 * @access private
	 *
	 * @param string $indent
	 * @param array  $data
	 * @param string $line
	 *
	 * @return string
	 */
	private function classDocBlock($indent, $data, $line) {
		$added_newline = false;

		$doc_block = "{$indent}/**\r\n";
		$doc_block .= "{$indent} * " . (preg_match('/abstract\s[^\{]/', $line) === 1 ? 'Abstract ' : '') . "{$data['name']} class\r\n";

		// Detect if class is abstract
		if (preg_match('/abstract\s[^\{]/', $line) === 1) {
			if (!$added_newline) {
				$added_newline = true;
				$doc_block .= "{$indent} *\r\n";
			}
			$doc_block .= "{$indent} * @abstract\r\n";
		}

		// Detect if class is final
		if (preg_match('/final\s[^\{]/', $line) === 1) {
			if (!$added_newline) {
				$added_newline = true;
				$doc_block .= "{$indent} *\r\n";
			}
			$doc_block .= "{$indent} * @final\r\n";
		}

		// Detect if class extends
		if (preg_match('/extends\s([^\{\s]*)/', $line, $matches) === 1) {
			if (!$added_newline) {
				$added_newline = true;
				$doc_block .= "{$indent} *\r\n";
			}
			$doc_block .= "{$indent} * @extends {$matches[1]}\r\n";
		}

		// Detect if class implements
		if (preg_match('/implements\s([^\{\s]*)/', $line, $matches) === 1) {
			if (!$added_newline) {
				$added_newline = true;
				$doc_block .= "{$indent} *\r\n";
			}
			$doc_block .= "{$indent} * @implements {$matches[1]}\r\n";
		}

		$doc_block .= "{$indent} */\r\n";

		return $doc_block;
	}

	/**
	 * interfaceDocBlock function
	 * DocBlock for interface
	 *
	 * @access private
	 *
	 * @param string $indent
	 * @param array  $data
	 * @param string $line
	 *
	 * @return string
	 */
	private function interfaceDocBlock($indent, $data, $line) {
		$doc_block = "{$indent}/**\r\n";
		$doc_block .= "{$indent} * {$data['name']} interface\r\n";

		// Detect if interface extends
		if (preg_match('/extends\s([^\{\s]*)/', $line, $matches) === 1) {
			$doc_block .= "{$indent} *\r\n";
			$doc_block .= "{$indent} * @extends {$matches[1]}\r\n";
		}

		$doc_block .= "{$indent} */\r\n";

		return $doc_block;
	}

	/**
	 * getStrIndent function
	 * Returns indentation section of a string
	 *
	 * @access private
	 *
	 * @param string $line
	 *
	 * @return string
	 */
	private function getStrIndent($line) {
		preg_match('/^\s*/', $line, $matches);
		return $matches[0];
	}
}

$stdin = file_get_contents('php://stdin');

$doc_block_gen = new DocBlockGen($stdin);
$doc_block_gen->start();
$doc_block_gen->result();
