<?php
/**
 * Stand-in for the parts of WP-CLI the commands use: PHPUnit calls the command class directly, PHPStan reads the
 * signatures. Output lands in WP_CLI::$out, error() and halt() throw Leitvia_Studio_Cli_Halt with the exit code.
 *
 * @package LeitviaStudio
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, Generic.Files.OneObjectStructurePerFile, Universal.Namespaces.DisallowCurlyBraceSyntax, Universal.Namespaces.OneDeclarationPerFile, Universal.Files.SeparateFunctionsFromOO, Universal.Namespaces.DisallowDeclarationWithoutName, Generic.CodeAnalysis.UnusedFunctionParameter

namespace {

	/**
	 * Exit of a command.
	 */
	class Leitvia_Studio_Cli_Halt extends Exception {}

	/**
	 * WP-CLI.
	 */
	class WP_CLI {

		/**
		 * Printed lines.
		 *
		 * @var list<string>
		 */
		public static array $out = array();

		/**
		 * Registers a command.
		 *
		 * @param string               $name     Name.
		 * @param callable             $handler Handler.
		 * @param array<string, mixed> $args     Arguments.
		 */
		public static function add_command( string $name, $handler, array $args = array() ): bool {
			return true;
		}

		/**
		 * Prints a line.
		 *
		 * @param string $message Message.
		 */
		public static function line( string $message = '' ): void {
			self::$out[] = $message;
		}

		/**
		 * Prints a log line.
		 *
		 * @param string $message Message.
		 */
		public static function log( string $message ): void {
			self::$out[] = $message;
		}

		/**
		 * Prints a success line.
		 *
		 * @param string $message Message.
		 */
		public static function success( string $message ): void {
			self::$out[] = 'Success: ' . $message;
		}

		/**
		 * Prints an error and exits with 1.
		 *
		 * @param string $message Message.
		 * @param bool   $should_exit Whether to exit.
		 * @throws Leitvia_Studio_Cli_Halt Always.
		 */
		public static function error( string $message, bool $should_exit = true ): never {
			throw new Leitvia_Studio_Cli_Halt( $message, 1 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test stand-in.
		}

		/**
		 * Exits with a code.
		 *
		 * @param int $code Exit code.
		 * @throws Leitvia_Studio_Cli_Halt Always.
		 */
		public static function halt( int $code ): never {
			throw new Leitvia_Studio_Cli_Halt( '', $code ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test stand-in.
		}
	}
}

namespace WP_CLI\Utils {

	/**
	 * Prints items as table, JSON or YAML; the stand-in records them as JSON.
	 *
	 * @param string       $format Format.
	 * @param array<mixed> $items  Items.
	 * @param string[]     $fields Fields.
	 */
	function format_items( string $format, array $items, array $fields ): void {
		\WP_CLI::$out[] = (string) wp_json_encode( compact( 'format', 'items', 'fields' ) );
	}
}
