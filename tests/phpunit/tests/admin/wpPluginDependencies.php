<?php
/**
 * Test WP_Plugin_Dependencies class.
 *
 * @package Plugin_Dependencies_Tab
 *
 * @group admin
 * @group plugins
 */

use WP_Plugin_Dependencies as Dependencies;

class Tests_Admin_WpPluginDependencies extends WP_UnitTestCase {
	/**
	 * @dataProvider data_slug_sanitization
	 *
	 * @covers WP_Plugin_Dependencies::sanitize_required_headers
	 *
	 * @param string $requires_plugins The unsanitized dependency slug(s).
	 * @param array $expected          The sanitized dependency slug(s).
	 */
	public function test_slug_sanitization( $requires_plugins, $expected ) {
		$headers = array( 'test-plugin' => array( 'RequiresPlugins' => $requires_plugins ) );
		$actual  = ( new Dependencies() )->sanitize_required_headers( $headers );
		$this->assertSame( $expected, $actual );
	}

	/**
	 * Data provider.
	 *
	 * @return array
	 */
	public function data_slug_sanitization() {
		return array(
			'one dependency'                         => array(
				'requires_plugins' => 'hello-dolly',
				'expected'         => array( 'hello-dolly' ),
			),
			'two dependencies in alphabetical order' => array(
				'requires_plugins' => 'hello-dolly, woocommerce',
				'expected'         => array( 'hello-dolly', 'woocommerce' ),
			),
			'two dependencies in reverse alphabetical order' => array(
				'requires_plugins' => 'woocommerce, hello-dolly',
				'expected'         => array( 'hello-dolly', 'woocommerce' ),
			),
			'two dependencies with a space'          => array(
				'requires_plugins' => 'hello-dolly , woocommerce',
				'expected'         => array( 'hello-dolly', 'woocommerce' ),
			),
			'a repeated dependency'                  => array(
				'requires_plugins' => 'hello-dolly, woocommerce, hello-dolly',
				'expected'         => array( 'hello-dolly', 'woocommerce' ),
			),
			'a dependency with an underscore'        => array(
				'requires_plugins' => 'hello_dolly',
				'expected'         => array(),
			),
			'a dependency with a space'              => array(
				'requires_plugins' => 'hello dolly',
				'expected'         => array(),
			),
			'a dependency in quotes'                 => array(
				'requires_plugins' => '"hello-dolly"',
				'expected'         => array(),
			),
			'two dependencies in quotes'             => array(
				'requires_plugins' => '"hello-dolly, woocommerce"',
				'expected'         => array(),
			),
		);
	}
}
