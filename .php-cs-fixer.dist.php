<?php

// Sets Directories to be included for CsFixer
$finder = new PhpCsFixer\Finder()
	->in(__DIR__.'/src')
	->in(__DIR__.'/tests')
	->exclude([]) // Add dirs to exclude
	->notPath([]) // Add filename patterns to exclude
;

// Sets rulesets & rules to be applied by CsFixer
return new PhpCsFixer\Config()
	->setRules([
		'@PSR12' => true,
		// Force short array syntax
		'array_syntax' => ['syntax' => 'short'],
		// Force linebreak between if/elseif/else
		'control_structure_continuation_position' => ['position' => 'next_line'],
		// Allow tab indents on blank lines
		'no_whitespace_in_blank_line' => false,
	])
	->setIndent("\t")
	->setFinder($finder)
	->setCacheFile('local/.php-cs-fixer.cache')
;