<?php
declare(strict_types = 1);

namespace Tests\Integration;

use Codeception\Test\Unit;
use Exception as BaseException;
use pozitronik\sys_options\models\SysOptions;
use Tests\Support\Helper\MigrationHelper;
use Tests\Support\IntegrationTester;
use Yii;
use yii\base\Exception;
use yii\base\InvalidConfigException;
use yii\base\InvalidRouteException;
use yii\caching\FileCache;

/**
 * Cache prefix configuration tests
 *
 * Verifies $cachePrefix property functionality for cache namespace isolation
 * between multiple SysOptions instances.
 */
class CachePrefixTest extends Unit {

	protected IntegrationTester $tester;

	/**
	 * @return void
	 * @throws InvalidConfigException
	 * @throws InvalidRouteException
	 * @throws \yii\console\Exception
	 */
	protected function _before():void {
		MigrationHelper::migrateFresh(['migrationPath' => ['@app/migrations/', '@app/../../migrations']]);

		// Configure real cache for these tests
		Yii::$app->set('cache', [
			'class' => FileCache::class,
		]);

		// Flush cache to prevent contamination between tests
		Yii::$app->cache->flush();
	}

	/**
	 * Verify cachePrefix defaults to null
	 *
	 */
	public function testCachePrefixDefaultsToNull():void {
		$options = new SysOptions();
		static::assertNull($options->cachePrefix, 'cachePrefix should default to null');
	}

	/**
	 * Verify default behavior uses static::class as cache key prefix
	 *
	 * @throws BaseException
	 */
	public function testDefaultCacheKeyUsesStaticClass():void {
		$options = new SysOptions();

		// Set and get option
		static::assertTrue($options->set('test_option', 'test_value'));
		static::assertEquals('test_value', $options->get('test_option'));

		// Verify cache key uses static::class format
		$expectedKey = SysOptions::class . "::get(test_option)";
		$cachedValue = Yii::$app->cache->get($expectedKey);
		static::assertNotFalse($cachedValue, 'Cache entry should exist with static::class prefix');
	}

	/**
	 * Verify custom cache prefix works correctly
	 *
	 * @throws BaseException
	 */
	public function testCustomCachePrefix():void {
		$options = new SysOptions(['cachePrefix' => 'CustomPrefix']);

		// Set and get option
		static::assertTrue($options->set('test_option', 'test_value'));
		static::assertEquals('test_value', $options->get('test_option'));

		// Verify cache key uses custom prefix
		$customKey = "CustomPrefix::get(test_option)";
		$cachedValue = Yii::$app->cache->get($customKey);
		static::assertNotFalse($cachedValue, 'Cache entry should exist with custom prefix');

		// Verify default key does NOT exist
		$defaultKey = SysOptions::class . "::get(test_option)";
		static::assertFalse(Yii::$app->cache->get($defaultKey), 'Default prefix key should not exist');
	}

	/**
	 * Verify multiple instances with different prefixes have isolated caches
	 *
	 * This is the primary use case: multiple SysOptions instances operating on
	 * different tables should not share cache.
	 *
	 * @throws Exception
	 */
	public function testMultipleInstancesWithDifferentPrefixesAreIsolated():void {
		// Create second table for instance2
		Yii::$app->db->createCommand()->createTable('sys_options_alt', [
			'id' => 'pk',
			'option' => 'string(256) NOT NULL',
			'value' => 'bytea',
		])->execute();
		Yii::$app->db->createCommand()->createIndex('option_unique', 'sys_options_alt', 'option', true)->execute();

		// Instance 1 with first table and prefix
		$options1 = new SysOptions([
			'tableName' => 'sys_options',
			'cachePrefix' => 'Instance1',
		]);

		// Instance 2 with different table and prefix
		$options2 = new SysOptions([
			'tableName' => 'sys_options_alt',
			'cachePrefix' => 'Instance2',
		]);

		// Set same option name in both instances with different values
		static::assertTrue($options1->set('shared_option', 'value_from_instance1'));
		static::assertTrue($options2->set('shared_option', 'value_from_instance2'));

		// Each instance should read its own value from its own table
		static::assertEquals('value_from_instance1', $options1->get('shared_option'));
		static::assertEquals('value_from_instance2', $options2->get('shared_option'));

		// Verify separate cache keys exist
		$key1 = "Instance1::get(shared_option)";
		$key2 = "Instance2::get(shared_option)";

		static::assertNotFalse(Yii::$app->cache->get($key1), 'Instance1 cache should exist');
		static::assertNotFalse(Yii::$app->cache->get($key2), 'Instance2 cache should exist');

		// Verify cached values are different
		$cached1 = Yii::$app->cache->get($key1);
		$cached2 = Yii::$app->cache->get($key2);
		static::assertNotEquals($cached1, $cached2, 'Cached values should be different');
	}

	/**
	 * Verify cache invalidation respects custom prefix
	 *
	 * @throws BaseException
	 */
	public function testCacheInvalidationWithCustomPrefix():void {
		$options = new SysOptions(['cachePrefix' => 'TestPrefix']);

		// Set initial value
		static::assertTrue($options->set('test_option', 'initial_value'));
		static::assertEquals('initial_value', $options->get('test_option'));

		// Verify cached
		$cacheKey = "TestPrefix::get(test_option)";
		static::assertNotFalse(Yii::$app->cache->get($cacheKey), 'Should be cached');

		// Update value
		static::assertTrue($options->set('test_option', 'updated_value'));

		// Cache should be invalidated
		static::assertFalse(Yii::$app->cache->get($cacheKey), 'Cache should be invalidated after set()');

		// Get should return updated value and re-cache
		static::assertEquals('updated_value', $options->get('test_option'));
		static::assertNotFalse(Yii::$app->cache->get($cacheKey), 'Should be re-cached after get()');
	}

	/**
	 * Verify drop() invalidates correct cache key with custom prefix
	 *
	 * @throws BaseException
	 */
	public function testDropInvalidatesCorrectCacheKeyWithCustomPrefix():void {
		$options = new SysOptions(['cachePrefix' => 'DropTest']);

		// Set and cache option
		static::assertTrue($options->set('test_option', 'test_value'));
		static::assertEquals('test_value', $options->get('test_option'));

		$cacheKey = "DropTest::get(test_option)";
		static::assertNotFalse(Yii::$app->cache->get($cacheKey), 'Should be cached');

		// Drop option
		static::assertTrue($options->drop('test_option'));

		// Cache should be invalidated
		static::assertFalse(Yii::$app->cache->get($cacheKey), 'Cache should be invalidated after drop()');
		static::assertNull($options->get('test_option'), 'Option should not exist after drop()');
	}

	/**
	 * Verify clear() invalidates all cache keys with custom prefix
	 *
	 * @throws BaseException
	 */
	public function testClearInvalidatesAllKeysWithCustomPrefix():void {
		$options = new SysOptions(['cachePrefix' => 'ClearTest']);

		// Set multiple options
		static::assertTrue($options->set('option1', 'value1'));
		static::assertTrue($options->set('option2', 'value2'));
		static::assertTrue($options->set('option3', 'value3'));

		// Cache all
		static::assertEquals('value1', $options->get('option1'));
		static::assertEquals('value2', $options->get('option2'));
		static::assertEquals('value3', $options->get('option3'));

		// Verify all cached
		$key1 = "ClearTest::get(option1)";
		$key2 = "ClearTest::get(option2)";
		$key3 = "ClearTest::get(option3)";

		static::assertNotFalse(Yii::$app->cache->get($key1), 'option1 should be cached');
		static::assertNotFalse(Yii::$app->cache->get($key2), 'option2 should be cached');
		static::assertNotFalse(Yii::$app->cache->get($key3), 'option3 should be cached');

		// Clear all options
		static::assertTrue($options->clear());

		// All cache keys should be invalidated
		static::assertFalse(Yii::$app->cache->get($key1), 'option1 cache should be cleared');
		static::assertFalse(Yii::$app->cache->get($key2), 'option2 cache should be cleared');
		static::assertFalse(Yii::$app->cache->get($key3), 'option3 cache should be cleared');
	}

	/**
	 * Verify legacy cache compatibility works with custom prefix
	 *
	 * @throws BaseException
	 */
	public function testLegacyCacheCompatibilityWithCustomPrefix():void {
		$options = new SysOptions([
			'cachePrefix' => 'LegacyTest',
			'legacyCacheCompatibility' => true,
		]);

		// Simulate v1.1.0 stale cache entry with custom prefix
		$cacheKey = "LegacyTest::get(nonexistent_option)";
		Yii::$app->cache->set($cacheKey, 'N;');  // v1.1.0 cached serialize(null)

		// Should detect and fix stale entry
		$result = $options->get('nonexistent_option', 'default_value');
		static::assertEquals('default_value', $result, 'Should return default value');

		// Stale cache should be deleted
		static::assertFalse(Yii::$app->cache->get($cacheKey), 'Stale cache should be deleted');
	}

	/**
	 * Verify empty string prefix works (edge case)
	 *
	 * @throws BaseException
	 */
	public function testEmptyStringPrefix():void {
		$options = new SysOptions(['cachePrefix' => '']);

		static::assertTrue($options->set('test_option', 'test_value'));
		static::assertEquals('test_value', $options->get('test_option'));

		// Cache key should be "::get(test_option)"
		$cacheKey = "::get(test_option)";
		$cachedValue = Yii::$app->cache->get($cacheKey);
		static::assertNotFalse($cachedValue, 'Empty prefix should work (creates "::get()" format)');
	}

	/**
	 * Verify prefix with special characters works
	 *
	 * @throws BaseException
	 */
	public function testPrefixWithSpecialCharacters():void {
		$options = new SysOptions(['cachePrefix' => 'App\\Module\\SysOptions']);

		static::assertTrue($options->set('test_option', 'test_value'));
		static::assertEquals('test_value', $options->get('test_option'));

		// Cache key should include backslashes
		$cacheKey = "App\\Module\\SysOptions::get(test_option)";
		$cachedValue = Yii::$app->cache->get($cacheKey);
		static::assertNotFalse($cachedValue, 'Prefix with backslashes should work');
	}

	/**
	 * Verify changing prefix on existing instance doesn't affect already cached data
	 *
	 * This documents the expected behavior: cache prefix is used at operation time,
	 * not pre-configured.
	 *
	 * @throws BaseException
	 */
	public function testChangingPrefixAfterInitialization():void {
		$options = new SysOptions(['cachePrefix' => 'OriginalPrefix']);

		// Set with original prefix
		static::assertTrue($options->set('test_option', 'test_value'));
		static::assertEquals('test_value', $options->get('test_option'));

		// Verify cached with original prefix
		$originalKey = "OriginalPrefix::get(test_option)";
		static::assertNotFalse(Yii::$app->cache->get($originalKey));

		// Change prefix (not recommended in production, but should work)
		$options->cachePrefix = 'NewPrefix';

		// Get should use NEW prefix (cache miss)
		$result = $options->get('test_option');
		static::assertEquals('test_value', $result, 'Should still retrieve from DB');

		// Should create cache entry with NEW prefix
		$newKey = "NewPrefix::get(test_option)";
		static::assertNotFalse(Yii::$app->cache->get($newKey), 'Should cache with new prefix');

		// Original cache entry still exists (orphaned)
		static::assertNotFalse(Yii::$app->cache->get($originalKey), 'Original cache still exists (orphaned)');
	}
}
