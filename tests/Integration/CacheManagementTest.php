<?php
declare(strict_types = 1);

namespace Tests\Integration;

use Codeception\Test\Unit;
use pozitronik\sys_options\models\SysOptions;
use Tests\Support\Helper\MigrationHelper;
use Tests\Support\IntegrationTester;
use Yii;
use yii\base\Exception as BaseException;
use yii\base\InvalidRouteException;
use yii\caching\FileCache;
use yii\console\Exception;

/**
 * Cache component management tests
 *
 * Tests behavior when cache component is missing, misconfigured,
 * or manually enabled/disabled.
 */
class CacheManagementTest extends Unit {

	protected IntegrationTester $tester;

	/**
	 * @return void
	 * @throws InvalidRouteException
	 * @throws Exception
	 */
	protected function _before():void {
		MigrationHelper::migrateFresh(['migrationPath' => ['@app/migrations/', '@app/../../migrations']]);
	}

	/**
	 * Verify correct cache invalidation timing
	 *
	 * Cache invalidates AFTER DB write, not before, to prevent race conditions.
	 *
	 * @throws BaseException
	 */
	public function testCacheInvalidationTiming():void {
		// Configure real cache instead of DummyCache
		Yii::$app->set('cache', [
			'class' => FileCache::class,
		]);

		$options = new SysOptions();

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
	 *
	 * When cache component is null, caching should be automatically disabled.
	 *
	 * @throws BaseException
	 */
	public function testMissingCacheComponent():void {
		// Remove cache component
		Yii::$app->set('cache', null);

		$options = new SysOptions();

		// Caching should be automatically disabled during initialization
		static::assertNull($options->cache, 'Cache should be null when cache component is not available');

		// These operations should work without exceptions (caching disabled)
		static::assertTrue($options->set('test_option', 'test_value'));
		static::assertEquals('test_value', $options->get('test_option'));
		static::assertTrue($options->drop('test_option'));
	}

	/**
	 * Verify cache component resolution failure disables caching gracefully
	 *
	 * When cache component string reference cannot be resolved (e.g., non-existent component),
	 * caching should be automatically disabled with warning logged.
	 *
	 * @throws BaseException
	 */
	public function testCacheComponentResolutionFailure():void {
		// Set cache to null
		Yii::$app->set('cache', null);

		// Create options with explicit cache component reference that doesn't exist
		$options = new SysOptions(['cache' => 'nonexistent_cache_component']);

		// Cache should be null after resolution failure
		static::assertNull($options->cache, 'Cache property should be null after resolution failure');

		// Operations should still work without cache
		static::assertTrue($options->set('test_option', 'test_value'));
		static::assertEquals('test_value', $options->get('test_option'));
	}

	/**
	 * Verify cache disabled via instance configuration
	 *
	 * @throws BaseException
	 */
	public function testCacheDisabledViaConfiguration():void {
		// Configure real cache
		Yii::$app->set('cache', [
			'class' => FileCache::class,
		]);

		// Create instance with disabled cache
		$options = new SysOptions(['cache' => null]);

		static::assertNull($options->cache, 'Cache should be null when explicitly disabled');
		static::assertTrue($options->set('no_cache_test', 'test_value'));
		static::assertEquals('test_value', $options->get('no_cache_test'));
	}

	/**
	 * Verify legacyCacheCompatibility is disabled by default
	 *
	 */
	public function testLegacyCacheCompatibilityDisabledByDefault():void {
		$options = new SysOptions();
		static::assertFalse($options->legacyCacheCompatibility, 'Legacy cache compatibility should be disabled by default');
	}

	/**
	 * Verify legacy v1.1.0 cache entries are detected and fixed when compatibility mode enabled
	 *
	 * Simulates v1.1.0 behavior: non-existent options cached as serialize(null) = "N;"
	 *
	 * @throws BaseException
	 */
	public function testLegacyCacheCompatibilityFixesNonExistentOptions():void {
		// Configure real cache
		Yii::$app->set('cache', [
			'class' => FileCache::class,
		]);

		// Simulate v1.1.0 cache entry for non-existent option
		$cacheKey = SysOptions::class . "::get(nonexistent_option)";
		Yii::$app->cache->set($cacheKey, 'N;');  // v1.1.0 cached serialize(null) for non-existent options

		// Test WITHOUT compatibility mode (reproduces the bug)
		$options1 = new SysOptions(['legacyCacheCompatibility' => false]);
		$result1 = $options1->get('nonexistent_option', 'default_value');
		static::assertNull($result1, 'Without compatibility mode, should return null (buggy behavior)');

		// Cache entry still exists
		static::assertNotFalse(Yii::$app->cache->get($cacheKey), 'Stale cache entry should still exist without fix');

		// Test WITH compatibility mode (fixes the bug)
		$options2 = new SysOptions(['legacyCacheCompatibility' => true]);
		$result2 = $options2->get('nonexistent_option', 'default_value');
		static::assertEquals('default_value', $result2, 'With compatibility mode, should return default value');

		// Cache entry should be cleaned up
		static::assertFalse(Yii::$app->cache->get($cacheKey), 'Stale cache entry should be deleted by compatibility fix');
	}

	/**
	 * Verify legacy compatibility mode correctly handles options with actual null values
	 *
	 * Options that exist in DB with null value should NOT be deleted from cache
	 *
	 * @throws BaseException
	 */
	public function testLegacyCacheCompatibilityPreservesRealNullValues():void {
		// Configure real cache
		Yii::$app->set('cache', [
			'class' => FileCache::class,
		]);

		$options = new SysOptions(['legacyCacheCompatibility' => true]);

		// Create option with actual null value
		static::assertTrue($options->set('option_with_null', null));

		// Manually set cache to "N;" (simulating v1.1.0 cache entry)
		$cacheKey = SysOptions::class . "::get(option_with_null)";
		Yii::$app->cache->set($cacheKey, 'N;');

		// Get should return null (not default), because option EXISTS with null value
		$result = $options->get('option_with_null', 'default_value');
		static::assertNull($result, 'Option exists with null value, should return null');

		// Cache should be re-cached with correct value (not deleted)
		$cachedValue = Yii::$app->cache->get($cacheKey);
		static::assertNotFalse($cachedValue, 'Cache entry should be re-cached, not deleted');
		static::assertEquals('N;', $cachedValue, 'Cache should contain serialized null');
	}

	/**
	 * Verify compatibility fix only triggers for "N;" cache values
	 *
	 * @throws BaseException
	 */
	public function testLegacyCacheCompatibilityOnlyTriggersForSerializedNull():void {
		// Configure real cache
		Yii::$app->set('cache', [
			'class' => FileCache::class,
		]);

		$options = new SysOptions(['legacyCacheCompatibility' => true]);

		// Set option with string value
		static::assertTrue($options->set('string_option', 'test_value'));

		// Get should work normally without triggering compatibility logic
		$result = $options->get('string_option');
		static::assertEquals('test_value', $result);

		// Set option with integer
		static::assertTrue($options->set('int_option', 42));
		static::assertEquals(42, $options->get('int_option'));

		// Set option with array
		static::assertTrue($options->set('array_option', ['key' => 'value']));
		static::assertEquals(['key' => 'value'], $options->get('array_option'));
	}

	/**
	 * Verify compatibility mode works correctly with cache miss scenario
	 *
	 * @throws BaseException
	 */
	public function testLegacyCacheCompatibilityWithCacheMiss():void {
		// Configure real cache
		Yii::$app->set('cache', [
			'class' => FileCache::class,
		]);

		$options = new SysOptions(['legacyCacheCompatibility' => true]);

		// Set option
		static::assertTrue($options->set('test_option', 'test_value'));

		// Clear cache to simulate cache miss
		$cacheKey = SysOptions::class . "::get(test_option)";
		Yii::$app->cache->delete($cacheKey);

		// Get should work normally (cache miss, query DB, re-cache)
		$result = $options->get('test_option');
		static::assertEquals('test_value', $result, 'Should retrieve from DB on cache miss');

		// Cache should be repopulated
		static::assertNotFalse(Yii::$app->cache->get($cacheKey), 'Cache should be repopulated after miss');
	}

	/**
	 * Verify compatibility mode is self-healing
	 *
	 * Once a stale cache entry is detected and fixed, subsequent calls should not
	 * trigger the compatibility logic (no extra DB queries)
	 *
	 * @throws BaseException
	 */
	public function testLegacyCacheCompatibilityIsSelfHealing():void {
		// Configure real cache
		Yii::$app->set('cache', [
			'class' => FileCache::class,
		]);

		// Simulate v1.1.0 stale cache entry
		$cacheKey = SysOptions::class . "::get(healed_option)";
		Yii::$app->cache->set($cacheKey, 'N;');

		$options = new SysOptions(['legacyCacheCompatibility' => true]);

		// First call: triggers fix, cleans cache
		$result1 = $options->get('healed_option', 'default');
		static::assertEquals('default', $result1);
		static::assertFalse(Yii::$app->cache->get($cacheKey), 'Stale cache should be deleted');

		// Second call: normal cache miss path (no compatibility logic triggered)
		$result2 = $options->get('healed_option', 'default');
		static::assertEquals('default', $result2);

		// Cache should still be empty (option doesn't exist in DB)
		static::assertFalse(Yii::$app->cache->get($cacheKey), 'Cache should remain empty for non-existent option');
	}
}
