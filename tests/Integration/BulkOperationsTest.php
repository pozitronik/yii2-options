<?php
declare(strict_types = 1);

namespace Tests\Integration;

use Codeception\Test\Unit;
use Exception;
use pozitronik\sys_options\models\SysOptions;
use Tests\Support\Helper\MigrationHelper;
use Tests\Support\IntegrationTester;
use Yii;
use yii\base\InvalidConfigException;
use yii\base\InvalidRouteException;
use yii\caching\FileCache;

/**
 * Bulk operation methods tests
 *
 * Tests for retrieveOptions(), getAllNames(), getByPattern(), and clear() methods.
 */
class BulkOperationsTest extends Unit {

	protected IntegrationTester $tester;

	/**
	 * @return void
	 * @throws InvalidConfigException
	 * @throws InvalidRouteException
	 * @throws \yii\console\Exception
	 */
	protected function _before():void {
		MigrationHelper::migrateFresh(['migrationPath' => ['@app/migrations/', '@app/../../migrations']]);

		// Configure real cache for cache-related tests
		Yii::$app->set('cache', [
			'class' => FileCache::class,
		]);
	}

	/**
	 * Verify retrieveOptions() returns empty array when no options exist
	 */
	public function testRetrieveOptionsReturnsEmptyArrayWhenNoOptions():void {
		$options = new SysOptions();
		$result = $options->retrieveOptions();

		static::assertIsArray($result, 'retrieveOptions() should return array');
		static::assertEmpty($result, 'retrieveOptions() should return empty array when no options exist');
	}

	/**
	 * Verify retrieveOptions() returns all stored options
	 *
	 * @throws Exception
	 */
	public function testRetrieveOptionsReturnsAllStoredOptions():void {
		$options = new SysOptions();

		// Store multiple options with different types
		static::assertTrue($options->set('string_option', 'test_value'));
		static::assertTrue($options->set('int_option', 42));
		static::assertTrue($options->set('array_option', ['key' => 'value']));
		static::assertTrue($options->set('null_option', null));
		static::assertTrue($options->set('bool_option', true));

		// Get all options
		$result = $options->retrieveOptions();

		static::assertIsArray($result);
		static::assertCount(5, $result, 'Should return all 5 options');

		// Verify each option
		static::assertEquals('test_value', $result['string_option']);
		static::assertEquals(42, $result['int_option']);
		static::assertEquals(['key' => 'value'], $result['array_option']);
		static::assertNull($result['null_option']);
		static::assertTrue($result['bool_option']);
	}

	/**
	 * Verify retrieveOptions() returns options as associative array with correct keys
	 *
	 * @throws Exception
	 */
	public function testRetrieveOptionsReturnsAssociativeArrayWithCorrectKeys():void {
		$options = new SysOptions();

		static::assertTrue($options->set('option1', 'value1'));
		static::assertTrue($options->set('option2', 'value2'));
		static::assertTrue($options->set('option3', 'value3'));

		$result = $options->retrieveOptions();

		// Verify keys
		static::assertArrayHasKey('option1', $result);
		static::assertArrayHasKey('option2', $result);
		static::assertArrayHasKey('option3', $result);

		// Verify it's associative (not numeric indexed)
		static::assertEquals(['option1', 'option2', 'option3'], array_keys($result));
	}

	/**
	 * Verify getAllNames() returns empty array when no options exist
	 */
	public function testGetAllNamesReturnsEmptyArrayWhenNoOptions():void {
		$options = new SysOptions();
		$result = $options->getAllNames();

		static::assertIsArray($result);
		static::assertEmpty($result);
	}

	/**
	 * Verify getAllNames() returns only option names without values
	 *
	 * @throws Exception
	 */
	public function testGetAllNamesReturnsOnlyOptionNames():void {
		$options = new SysOptions();

		static::assertTrue($options->set('name1', 'value1'));
		static::assertTrue($options->set('name2', 'value2'));
		static::assertTrue($options->set('name3', 'value3'));

		$result = $options->getAllNames();

		static::assertIsArray($result);
		static::assertCount(3, $result);

		// Should contain only names
		static::assertContains('name1', $result);
		static::assertContains('name2', $result);
		static::assertContains('name3', $result);

		// Should not contain values
		static::assertNotContains('value1', $result);
		static::assertNotContains('value2', $result);
		static::assertNotContains('value3', $result);
	}

	/**
	 * Verify getByPattern() with prefix pattern (app.%)
	 *
	 * @throws Exception
	 */
	public function testGetByPatternWithPrefixWildcard():void {
		$options = new SysOptions();

		// Store options with different prefixes
		static::assertTrue($options->set('app.cache', 'cache_value'));
		static::assertTrue($options->set('app.debug', true));
		static::assertTrue($options->set('app.name', 'MyApp'));
		static::assertTrue($options->set('db.host', 'localhost'));
		static::assertTrue($options->set('db.port', 5432));

		// Get options matching 'app.%'
		$result = $options->getByPattern('app.%');

		static::assertIsArray($result);
		static::assertCount(3, $result, 'Should return only 3 options starting with "app."');

		// Verify correct options returned
		static::assertArrayHasKey('app.cache', $result);
		static::assertArrayHasKey('app.debug', $result);
		static::assertArrayHasKey('app.name', $result);

		// Verify db.* options NOT included
		static::assertArrayNotHasKey('db.host', $result);
		static::assertArrayNotHasKey('db.port', $result);
	}

	/**
	 * Verify getByPattern() with contains pattern (%config%)
	 *
	 * @throws Exception
	 */
	public function testGetByPatternWithContainsWildcard():void {
		$options = new SysOptions();

		static::assertTrue($options->set('app_config', 'app_value'));
		static::assertTrue($options->set('db_config', 'db_value'));
		static::assertTrue($options->set('user_config_file', 'file_value'));
		static::assertTrue($options->set('cache_enabled', true));
		static::assertTrue($options->set('debug_mode', false));

		// Get options containing 'config'
		$result = $options->getByPattern('%config%');

		static::assertIsArray($result);
		static::assertCount(3, $result, 'Should return 3 options containing "config"');

		static::assertArrayHasKey('app_config', $result);
		static::assertArrayHasKey('db_config', $result);
		static::assertArrayHasKey('user_config_file', $result);

		static::assertArrayNotHasKey('cache_enabled', $result);
		static::assertArrayNotHasKey('debug_mode', $result);
	}

	/**
	 * Verify getByPattern() with single character wildcard (_)
	 *
	 * @throws Exception
	 */
	public function testGetByPatternWithSingleCharacterWildcard():void {
		$options = new SysOptions();

		static::assertTrue($options->set('option1', 'value1'));
		static::assertTrue($options->set('option2', 'value2'));
		static::assertTrue($options->set('option3', 'value3'));
		static::assertTrue($options->set('option10', 'value10'));

		// Get options matching 'option_' (single digit)
		$result = $options->getByPattern('option_');

		static::assertIsArray($result);
		static::assertCount(3, $result, 'Should match option1, option2, option3 (not option10)');

		static::assertArrayHasKey('option1', $result);
		static::assertArrayHasKey('option2', $result);
		static::assertArrayHasKey('option3', $result);
		static::assertArrayNotHasKey('option10', $result);
	}

	/**
	 * Verify getByPattern() returns empty array when no matches
	 *
	 * @throws Exception
	 */
	public function testGetByPatternReturnsEmptyArrayWhenNoMatches():void {
		$options = new SysOptions();

		static::assertTrue($options->set('option1', 'value1'));
		static::assertTrue($options->set('option2', 'value2'));

		// Pattern that matches nothing
		$result = $options->getByPattern('nomatch%');

		static::assertIsArray($result);
		static::assertEmpty($result);
	}

	/**
	 * Verify clear() deletes all options
	 *
	 * @throws Exception
	 */
	public function testClearDeletesAllOptions():void {
		$options = new SysOptions();

		// Store multiple options
		static::assertTrue($options->set('option1', 'value1'));
		static::assertTrue($options->set('option2', 'value2'));
		static::assertTrue($options->set('option3', 'value3'));

		// Verify options exist
		static::assertCount(3, $options->retrieveOptions());

		// Clear all options
		static::assertTrue($options->clear(), 'clear() should return true on success');

		// Verify all options deleted
		static::assertEmpty($options->retrieveOptions(), 'All options should be deleted');
		static::assertEmpty($options->getAllNames(), 'No option names should remain');

		// Verify individual gets return null
		static::assertNull($options->get('option1'));
		static::assertNull($options->get('option2'));
		static::assertNull($options->get('option3'));
	}

	/**
	 * Verify clear() on empty table returns true
	 */
	public function testClearOnEmptyTableReturnsTrue():void {
		$options = new SysOptions();

		// Table is empty - clear should still succeed
		static::assertTrue($options->clear());
		static::assertEmpty($options->retrieveOptions());
	}

	/**
	 * Verify clear() invalidates cache when caching is enabled
	 *
	 * @throws Exception
	 */
	public function testClearInvalidatesCache():void {
		$options = new SysOptions();
		$options->cacheEnabled = true;

		// Set and cache some options
		static::assertTrue($options->set('option1', 'value1'));
		static::assertTrue($options->set('option2', 'value2'));

		// Populate cache by reading
		static::assertEquals('value1', $options->get('option1'));
		static::assertEquals('value2', $options->get('option2'));

		// Verify cache contains values
		$cacheKey1 = SysOptions::class . "::get(option1)";
		$cacheKey2 = SysOptions::class . "::get(option2)";
		static::assertNotFalse(Yii::$app->cache->get($cacheKey1), 'option1 should be cached before clear');
		static::assertNotFalse(Yii::$app->cache->get($cacheKey2), 'option2 should be cached before clear');

		// Clear all options - should invalidate cache
		static::assertTrue($options->clear());

		// Cache should be invalidated
		static::assertFalse(Yii::$app->cache->get($cacheKey1), 'Cache should be invalidated after clear()');
		static::assertFalse(Yii::$app->cache->get($cacheKey2), 'Cache should be invalidated after clear()');

		// Getting options should return null (not cached values)
		static::assertNull($options->get('option1'));
		static::assertNull($options->get('option2'));
	}

	/**
	 * Verify clear() with caching disabled does not invalidate cache
	 *
	 * @throws Exception
	 */
	public function testClearWithCachingDisabledDoesNotInvalidateCache():void {
		// First instance with caching enabled
		$options1 = new SysOptions();
		$options1->cacheEnabled = true;

		// Set and cache an option
		static::assertTrue($options1->set('cached_option', 'cached_value'));
		static::assertEquals('cached_value', $options1->get('cached_option'));

		// Verify it's cached
		$cacheKey = SysOptions::class . "::get(cached_option)";
		static::assertNotFalse(Yii::$app->cache->get($cacheKey), 'Option should be cached');

		// Second instance with caching disabled
		$options2 = new SysOptions();
		$options2->cacheEnabled = false;

		// Clear with caching disabled - should NOT invalidate cache
		static::assertTrue($options2->clear());

		// Cache should still exist (was not invalidated)
		static::assertNotFalse(Yii::$app->cache->get($cacheKey),
			'Cache should NOT be invalidated when clear() is called with cacheEnabled=false');

		// But database is empty
		static::assertEmpty($options2->retrieveOptions());
	}

	/**
	 * Verify clear() and then adding new options works correctly
	 *
	 * @throws Exception
	 */
	public function testClearAndThenAddNewOptions():void {
		$options = new SysOptions();

		// Add initial options
		static::assertTrue($options->set('old1', 'old_value1'));
		static::assertTrue($options->set('old2', 'old_value2'));

		// Clear
		static::assertTrue($options->clear());
		static::assertEmpty($options->retrieveOptions());

		// Add new options
		static::assertTrue($options->set('new1', 'new_value1'));
		static::assertTrue($options->set('new2', 'new_value2'));

		// Verify only new options exist
		$all = $options->retrieveOptions();
		static::assertCount(2, $all);
		static::assertArrayHasKey('new1', $all);
		static::assertArrayHasKey('new2', $all);
		static::assertArrayNotHasKey('old1', $all);
		static::assertArrayNotHasKey('old2', $all);
	}

	/**
	 * Verify bulk methods handle large number of options
	 *
	 * @throws Exception
	 */
	public function testBulkMethodsHandleLargeNumberOfOptions():void {
		$options = new SysOptions();

		// Create 100 options
		for ($i = 1; $i <= 100; $i++) {
			static::assertTrue($options->set("option_{$i}", "value_{$i}"));
		}

		// Test retrieveOptions()
		$all = $options->retrieveOptions();
		static::assertCount(100, $all);

		// Test getAllNames()
		$names = $options->getAllNames();
		static::assertCount(100, $names);

		// Test getByPattern()
		$pattern1 = $options->getByPattern('option_1%'); // option_1, option_10-19, option_100
		static::assertCount(12, $pattern1);

		$pattern2 = $options->getByPattern('option_2_'); // option_20-29
		static::assertCount(10, $pattern2);

		// Test clear()
		static::assertTrue($options->clear());
		static::assertEmpty($options->retrieveOptions());
	}

	/**
	 * Verify retrieveOptions() with complex data types
	 *
	 * @throws Exception
	 */
	public function testRetrieveOptionsWithComplexDataTypes():void {
		$options = new SysOptions();

		$complexArray = [
			'nested' => ['key' => 'value'],
			'numbers' => [1, 2, 3, 4, 5],
			'mixed' => ['string', 123, true, null],
		];

		static::assertTrue($options->set('complex', $complexArray));
		static::assertTrue($options->set('float', 3.14159));
		static::assertTrue($options->set('negative', -42));

		$result = $options->retrieveOptions();

		static::assertEquals($complexArray, $result['complex']);
		static::assertEquals(3.14159, $result['float']);
		static::assertEquals(-42, $result['negative']);
	}
}
