<?php
declare(strict_types = 1);

namespace Tests\Integration;

use Codeception\Test\Unit;
use Exception;
use pozitronik\sys_options\models\SysOptions;
use Tests\Support\Helper\MigrationHelper;
use Tests\Support\IntegrationTester;
use Throwable;
use yii\base\InvalidRouteException;

/**
 * Option name validation tests
 *
 * Tests validation rules for option names: empty names, length limits,
 * special characters, and SQL injection protection.
 */
class ValidationTest extends Unit {

	protected IntegrationTester $tester;

	/**
	 * @return void
	 * @throws InvalidRouteException
	 * @throws \yii\console\Exception
	 */
	protected function _before():void {
		MigrationHelper::migrateFresh(['migrationPath' => ['@app/migrations/', '@app/../../migrations']]);
	}

	/**
	 * Verify empty option name is rejected
	 *
	 * @throws Exception
	 */
	public function testEmptyOptionName():void {
		$options = new SysOptions();

		// Empty string should not be valid name
		$this->expectException(Throwable::class);
		$options->set('', 'value');
	}

	/**
	 * Verify option names over 256 characters are rejected
	 *
	 * @throws Exception
	 */
	public function testTooLongOptionName():void {
		$options = new SysOptions();

		// Generate 257 character name
		$longName = str_repeat('a', 257);

		// Should throw exception or return false
		$this->expectException(Throwable::class);
		$options->set($longName, 'value');
	}

	/**
	 * Verify special characters in option name are supported
	 *
	 * @throws Exception
	 */
	public function testSpecialCharactersInOptionName():void {
		$options = new SysOptions();

		// Test various special characters
		$specialNames = [
			'option.with.dots',
			'option-with-dashes',
			'option_with_underscores',
			'option:with:colons',
			'option/with/slashes',
			'опция_на_русском',
		];

		foreach ($specialNames as $name) {
			static::assertTrue($options->set($name, 'test_value'),
				"Should support option with name: {$name}");
			static::assertEquals('test_value', $options->get($name));
			static::assertTrue($options->drop($name));
		}
	}

	/**
	 * Verify SQL injection protection in option name
	 *
	 * Potentially dangerous names should be handled safely with parameterized queries.
	 *
	 * @throws Exception
	 */
	public function testSqlInjectionInOptionName():void {
		$options = new SysOptions();

		// Potentially dangerous name
		$dangerousName = "'; DROP TABLE sys_options; --";

		// Should be handled safely
		static::assertTrue($options->set($dangerousName, 'test_value'));
		static::assertEquals('test_value', $options->get($dangerousName));
		static::assertTrue($options->drop($dangerousName));

		// Check that table still exists
		$options->set('check', 'table_exists');
		static::assertEquals('table_exists', $options->get('check'));
	}
}
