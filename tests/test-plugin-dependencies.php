<?php

/**
 * Class PluginDependencies
 *
 * @package Plugin_Dependencies_Tab
 */

use WP_Plugin_Dependencies as Dependencies;

/**
 * Sample test case.
 */
class PluginDependencies extends WP_UnitTestCase
{

	/**
	 * A single example test.
	 */
	public function test_sample()
	{
		// Replace this with some actual testing code.
		$this->assertTrue(true);
	}

	public function slug_data()
	{
		return [
			[['test' => ['RequiresPlugins' => 'hello-dolly']], ['hello-dolly']],
			[['test2' => ['RequiresPlugins' => 'hello-dolly, woocommerce']], ['hello-dolly', 'woocommerce']],
			[['test3' => ['RequiresPlugins' => 'woocommerce, hello-dolly']], ['hello-dolly', 'woocommerce']],
			[['test4' => ['RequiresPlugins' => 'hello-dolly,gutenberg,  "junk-list , here for test", 435_bad']], ['gutenberg', 'hello-dolly']],
		];
	}

	/**
	 * @dataProvider slug_data
	 *
	 * @return void
	 */
	public function test_slug_sanitization($headers, $expected)
	{
		$actual = (new Dependencies())->sanitize_required_headers($headers);
		$this->assertSame($expected, $actual);
	}

}
