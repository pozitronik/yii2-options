<?php
declare(strict_types = 1);

namespace Tests\Unit;

use Codeception\Test\Unit;
use Exception;
use pozitronik\sys_options\models\SysOptions;
use pozitronik\sys_options\SysOptionsModule;
use stdClass;
use Tests\Support\Helper\MigrationHelper;
use Tests\Support\UnitTester;
use Throwable;
use Yii;
use yii\base\Exception as BaseException;
use yii\caching\FileCache;
use yii\db\Exception as DbException;

/**
 * Tests for edge cases and identified issues
 */
class EdgeCasesTest extends Unit {

	protected UnitTester $tester;

	/**
	 * @Override
	 */
	protected function _before():void {
		MigrationHelper::migrateFresh(['migrationPath' => ['@app/migrations/', '@app/../../migrations']]);
	}

	/**
	 * Verify explicit null value storage
	 * Problem: unable to distinguish "option doesn't exist" from "option set to null"
	 * @return void
	 * @throws Exception
	 */
	public function testExplicitNullValueStorage():void {
		$options = new SysOptions();

		// Set explicit null
		static::assertTrue($options->set('null_option', null));

		// Get value - should return null, not default
		$value = $options->get('null_option', 'default_value');

		// Expect null to be returned, not 'default_value'
		static::assertNull($value, 'Explicitly set null should be returned as null');

		// Check non-existent option - should return default
		$nonExistent = $options->get('non_existent_option', 'default_value');
		static::assertEquals('default_value', $nonExistent, 'Non-existent option should return default');
	}

	/**
	 * Verify distinction between null and non-existent option
	 * @return void
	 * @throws Throwable
	 */
	public function testNullVsNonExistentOption():void {
		$options = new SysOptions();

		// Set null
		$options->set('explicit_null', null);

		// Check that we can distinguish null from non-existent option
		$nullValue = $options->get('explicit_null', 'default_for_null');
		$nonExistent = $options->get('never_set', 'default_for_nonexistent');

		// After fix: null should return null, non-existent should return default
		static::assertNotEquals($nullValue, $nonExistent, 'Explicitly set null should differ from non-existent option');
	}

	/**
	 * Verify correct cache invalidation timing
	 * Problem: cache invalidates BEFORE DB write, which can lead to inconsistent state
	 * @return void
	 * @throws BaseException
	 */
	public function testCacheInvalidationTiming():void {
		// Configure real cache instead of DummyCache
		Yii::$app->set('cache', [
			'class' => FileCache::class,
		]);

		$options = new SysOptions();
		$options->cacheEnabled = true;

		// Set value
		$options->set('cached_option', 'initial_value');

		// Read value (will be cached)
		$value1 = $options->get('cached_option');
		static::assertEquals('initial_value', $value1);

		// Update value
		$options->set('cached_option', 'updated_value');

		// Read again - should return updated value from DB, not from cache
		$value2 = $options->get('cached_option');
		static::assertEquals('updated_value', $value2, 'After update should return new value, not cached old one');
	}

	/**
	 * Verify operation with missing cache component
	 * @return void
	 * @throws BaseException
	 */
	public function testMissingCacheComponent():void {
		// Remove cache component
		Yii::$app->set('cache', null);

		$options = new SysOptions();

		// Caching should be automatically disabled during initialization
		static::assertFalse($options->cacheEnabled, 'Caching should be disabled when cache component is null');

		// These operations should work without exceptions (caching disabled)
		static::assertTrue($options->set('test_option', 'test_value'));
		static::assertEquals('test_value', $options->get('test_option'));
		static::assertTrue($options->drop('test_option'));
	}

	/**
	 * Verify that manually enabling cache when component is null throws exception
	 * @return void
	 * @throws BaseException
	 */
	public function testManuallyEnablingCacheWithNullComponentThrowsException():void {
		// Remove cache component
		Yii::$app->set('cache', null);

		$options = new SysOptions();

		// Manually override cacheEnabled (user shooting their own leg)
		$options->cacheEnabled = true;

		// Should throw exception when trying to use cache operations
		$this->expectException(Throwable::class);
		$options->set('test_option', 'test_value');
	}

	/**
	 * Verify custom serializer validation - invalid configuration
	 * Problem: no validation that $serializer is array with indices 0 and 1
	 * Note: With PHP 8.4 strict typing, we can't assign non-array to $serializer property,
	 * so this test validates that empty array is rejected
	 * @return void
	 */
	public function testInvalidSerializerConfiguration():void {
		$this->expectException(Throwable::class);

		// Empty array - invalid configuration
		new SysOptions(['serializer' => []]);
	}

	/**
	 * Verify serializer validation - array without required indices
	 * @return void
	 */
	public function testSerializerMissingIndices():void {
		$this->expectException(Throwable::class);

		// Array without index 0
		new SysOptions(['serializer' => [1 => fn(string $v) => json_decode($v, true)]]);
	}

	/**
	 * Verify serializer validation - non-callable values
	 * @return void
	 */
	public function testSerializerNotCallable():void {
		$this->expectException(Throwable::class);

		// Non-callable values
		new SysOptions(['serializer' => [0 => 'not_callable', 1 => 'also_not_callable']]);
	}

	/**
	 * Verify custom serializer functionality (JSON)
	 * @return void
	 * @throws Exception
	 */
	public function testCustomJsonSerializer():void {
		$options = new SysOptions();
		$options->serializer = [
			0 => fn($value) => json_encode($value),
			1 => fn(string $value) => json_decode($value, true),
		];

		$testData = ['key' => 'value', 'number' => 42];
		static::assertTrue($options->set('json_option', $testData));

		$retrieved = $options->get('json_option');
		static::assertEquals($testData, $retrieved);
	}

	/**
	 * Verify empty option name validation
	 * Problem: no option name validation
	 * @return void
	 * @throws Exception
	 */
	public function testEmptyOptionName():void {
		$options = new SysOptions();

		// Empty string should not be valid name
		$this->expectException(Throwable::class);
		$options->set('', 'value');
	}

	/**
	 * Verify too long option name validation
	 * Problem: names >256 characters not validated
	 * @return void
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
	 * Verify special characters in option name
	 * @return void
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
	 * @return void
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

	/**
	 * Verify upsert behavior (updating existing value)
	 * @return void
	 * @throws BaseException
	 */
	public function testUpsertBehavior():void {
		$options = new SysOptions();

		// Create option
		static::assertTrue($options->set('upsert_test', 'initial_value'));
		static::assertEquals('initial_value', $options->get('upsert_test'));

		// Update same option
		static::assertTrue($options->set('upsert_test', 'updated_value'));
		static::assertEquals('updated_value', $options->get('upsert_test'));

		// Check that DB has only one record (UPDATE, not INSERT)
		$count = Yii::$app->db->createCommand(
			'SELECT COUNT(*) FROM sys_options WHERE option = :option',
			[':option' => 'upsert_test']
		)->queryScalar();

		static::assertEquals(1, $count, 'Should be only one record (UPDATE instead of INSERT)');
	}

	/**
	 * Verify custom table name configuration
	 * @return void
	 * @throws BaseException
	 * @throws Throwable
	 */
	public function testCustomTableNameConfiguration():void {
		// Create custom table
		$customTableName = 'custom_options_table';
		Yii::$app->db->createCommand()->createTable($customTableName, [
			'id' => 'pk',
			'option' => 'string(256) NOT NULL',
			'value' => 'binary NULL',
		])->execute();

		Yii::$app->db->createCommand()->createIndex(
			'idx_custom_option',
			$customTableName,
			'option',
			true
		)->execute();

		// Configure module with custom table name
		Yii::$app->setModule('sysoptions', [
			'class' => SysOptionsModule::class,
			'params' => [
				'tableName' => $customTableName,
			]
		]);

		// Create new instance - it should use custom table
		$options = new SysOptions();

		static::assertTrue($options->set('custom_table_test', 'test_value'));
		static::assertEquals('test_value', $options->get('custom_table_test'));

		// Check that data is in custom table
		$value = Yii::$app->db->createCommand(
			"SELECT value FROM {$customTableName} WHERE option = :option",
			[':option' => 'custom_table_test']
		)->queryScalar();

		static::assertNotFalse($value, 'Data should be in custom table');

		// Cleanup
		Yii::$app->db->createCommand()->dropTable($customTableName)->execute();
	}

	/**
	 * Verify cache disabled via module configuration
	 * @return void
	 * @throws BaseException
	 */
	public function testCacheDisabledViaConfiguration():void {
		// Configure real cache
		Yii::$app->set('cache', [
			'class' => FileCache::class,
		]);

		// Configure module with disabled cache
		Yii::$app->setModule('sysoptions', [
			'class' => SysOptionsModule::class,
			'params' => [
				'cacheEnabled' => false,
			]
		]);

		$options = new SysOptions();

		// cacheEnabled should be false from module configuration
		static::assertFalse($options->cacheEnabled,
			'cacheEnabled should be false from module configuration');

		static::assertTrue($options->set('no_cache_test', 'test_value'));
		static::assertEquals('test_value', $options->get('no_cache_test'));
	}

	/**
	 * Verify object serialization
	 * @return void
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
	 * Verify large data storage
	 * @return void
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
	 * Verify storage of various data types
	 * @return void
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

	/**
	 * Verify error handling on database read
	 * Problem: no try-catch in retrieveDbValue
	 * @return void
	 * @throws DbException
	 */
	public function testErrorHandlingOnDatabaseRead():void {
		$options = new SysOptions();

		// Temporarily drop table to simulate error
		Yii::$app->db->createCommand('DROP TABLE sys_options')->execute();

		// Should handle error, not throw exception
		$result = $options->get('any_option', 'default_value');

		// After fix: should return default or null, not throw exception
		static::assertEquals('default_value', $result,
			'On DB error should return default value');
	}

	/**
	 * Verify error handling on database write
	 * @return void
	 * @throws DbException
	 */
	public function testErrorHandlingOnDatabaseWrite():void {
		$options = new SysOptions();

		// Save value for verification
		$options->set('test_before_drop', 'value');

		// Drop table
		Yii::$app->db->createCommand('DROP TABLE sys_options')->execute();

		// Should return false, not throw exception
		$result = $options->set('test_option', 'test_value');
		static::assertFalse($result, 'On DB error set() should return false');
	}

	/**
	 * Verify error handling on database delete
	 * @return void
	 * @throws DbException
	 */
	public function testErrorHandlingOnDatabaseDelete():void {
		$options = new SysOptions();

		// Drop table
		Yii::$app->db->createCommand('DROP TABLE sys_options')->execute();

		// Should return false, not throw exception
		$result = $options->drop('test_option');
		static::assertFalse($result, 'On DB error drop() should return false');
	}

	/**
	 * Verify table name accessibility
	 * Problem: $_tableName is private, no getter
	 * @return void
	 */
	public function testTableNameAccessibility():void {
		$options = new SysOptions();

		// Should have public getter for table name
		$tableName = $options->getTableName();
		static::assertEquals('sys_options', $tableName, 'Should have access to table name');
	}

	/**
	 * Verify concurrent access (multiple operations)
	 * @return void
	 * @throws BaseException
	 */
	public function testConcurrentOperations():void {
		$options = new SysOptions();

		// Simulate multiple operations with one option
		$options->set('concurrent_test', 'value1');
		$options->set('concurrent_test', 'value2');
		$options->set('concurrent_test', 'value3');

		$final = $options->get('concurrent_test');
		static::assertEquals('value3', $final, 'Should return last set value');

		// Check that DB has only one record
		$count = Yii::$app->db->createCommand(
			'SELECT COUNT(*) FROM sys_options WHERE option = :option',
			[':option' => 'concurrent_test']
		)->queryScalar();

		static::assertEquals(1, $count);
	}
}
