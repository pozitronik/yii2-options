<?php
declare(strict_types = 1);

namespace Tests\Integration;

use Codeception\Test\Unit;
use Exception;
use pozitronik\sys_options\models\SysOptions;
use stdClass;
use Tests\Support\Helper\MigrationHelper;
use Tests\Support\IntegrationTester;
use yii\base\InvalidRouteException;

/**
 * Data type handling tests
 *
 * Tests serialization and storage of various PHP data types:
 * objects, large data, arrays, primitives, and edge case values.
 */
class DataTypesTest extends Unit {

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
	 * Verify object serialization works correctly
	 *
	 * @throws Exception
	 */
	public function testObjectSerialization():void {
		$options = new SysOptions();

		$object = new stdClass();
		$object->property = 'value';
		$object->number = 42;

		static::assertTrue($options->set('object_option', $object));

		$retrieved = $options->get('object_option');
		static::assertInstanceOf(stdClass::class, $retrieved);
		static::assertEquals('value', $retrieved->property);
		static::assertEquals(42, $retrieved->number);
	}

	/**
	 * Verify large data storage (approximately 1MB)
	 *
	 * @throws Exception
	 */
	public function testLargeDataStorage():void {
		$options = new SysOptions();

		// Generate large data array (~1MB)
		$largeData = array_fill(0, 10000, str_repeat('x', 100));

		static::assertTrue($options->set('large_data', $largeData));

		$retrieved = $options->get('large_data');
		static::assertEquals($largeData, $retrieved);
		static::assertCount(10000, $retrieved);
	}

	/**
	 * Verify storage of various data types including edge cases
	 *
	 * Tests: booleans, zero values, negative numbers, empty values, nested arrays.
	 *
	 * @throws Exception
	 */
	public function testVariousDataTypes():void {
		$options = new SysOptions();

		$testCases = [
			'boolean_true' => true,
			'boolean_false' => false,
			'zero_integer' => 0,
			'negative_integer' => -42,
			'zero_float' => 0.0,
			'negative_float' => -3.14,
			'empty_string' => '',
			'empty_array' => [],
			'nested_array' => ['a' => ['b' => ['c' => 'deep']]],
		];

		foreach ($testCases as $key => $value) {
			static::assertTrue($options->set($key, $value), "Failed to save: {$key}");
			$retrieved = $options->get($key);
			static::assertSame($value, $retrieved, "Incorrect value for: {$key}");
		}
	}
}
