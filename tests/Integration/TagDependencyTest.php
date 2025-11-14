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
use yii\caching\TagDependency;

/**
 * TagDependency caching behavior tests
 *
 * Verifies cache persistence, invalidation, and tag-based selective clearing
 * using real cache backend (FileCache).
 */
class TagDependencyTest extends Unit {

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
	}

	/**
	 * Verify cache is populated on first get() and reused on subsequent calls
	 *
	 * @throws Exception
	 */
	public function testCachePersistenceAcrossMultipleGets():void {
		$options = new SysOptions();

		// Set value
		static::assertTrue($options->set('test_option', 'test_value'));

		// First get() - should read from DB and populate cache
		static::assertEquals('test_value', $options->get('test_option'));

		// Manually verify cache is populated
		$cacheKey = SysOptions::class . "::get(test_option)";
		$cachedValue = Yii::$app->cache->get($cacheKey);
		static::assertNotFalse($cachedValue, 'Cache should be populated after first get()');

		// Second get() - should read from cache (not DB)
		static::assertEquals('test_value', $options->get('test_option'));

		// Multiple subsequent gets should all return cached value
		for ($i = 0; $i < 10; $i++) {
			static::assertEquals('test_value', $options->get('test_option'),
				"get() call #{$i} should return cached value");
		}
	}

	/**
	 * Verify TagDependency invalidation is scoped to specific option
	 *
	 * Invalidating cache for option1 should NOT invalidate cache for option2
	 *
	 * @throws Exception
	 */
	public function testTagInvalidationScopedToSpecificOption():void {
		$options = new SysOptions();

		// Set and cache two different options
		static::assertTrue($options->set('option1', 'value1'));
		static::assertTrue($options->set('option2', 'value2'));

		// Populate cache for both
		static::assertEquals('value1', $options->get('option1'));
		static::assertEquals('value2', $options->get('option2'));

		// Verify both are cached
		$cacheKey1 = SysOptions::class . "::get(option1)";
		$cacheKey2 = SysOptions::class . "::get(option2)";
		static::assertNotFalse(Yii::$app->cache->get($cacheKey1), 'option1 should be cached');
		static::assertNotFalse(Yii::$app->cache->get($cacheKey2), 'option2 should be cached');

		// Update option1 - should invalidate ONLY option1 cache
		static::assertTrue($options->set('option1', 'new_value1'));

		// option1 cache should be invalidated
		static::assertFalse(Yii::$app->cache->get($cacheKey1),
			'option1 cache should be invalidated after set()');

		// option2 cache should still exist
		static::assertNotFalse(Yii::$app->cache->get($cacheKey2),
			'option2 cache should NOT be invalidated when option1 is updated');

		// Verify values are correct
		static::assertEquals('new_value1', $options->get('option1'));
		static::assertEquals('value2', $options->get('option2'));
	}

	/**
	 * Verify tag invalidation across multiple options with mass updates
	 *
	 * @throws Exception
	 */
	public function testTagInvalidationWithMultipleOptions():void {
		$options = new SysOptions();

		// Create 10 options
		for ($i = 1; $i <= 10; $i++) {
			static::assertTrue($options->set("option{$i}", "value{$i}"));
		}

		// Read and cache all options
		for ($i = 1; $i <= 10; $i++) {
			static::assertEquals("value{$i}", $options->get("option{$i}"));
		}

		// Verify all are cached
		for ($i = 1; $i <= 10; $i++) {
			$cacheKey = SysOptions::class . "::get(option{$i})";
			static::assertNotFalse(Yii::$app->cache->get($cacheKey),
				"option{$i} should be cached");
		}

		// Update options 3, 5, 7 - should invalidate ONLY those caches
		static::assertTrue($options->set('option3', 'new_value3'));
		static::assertTrue($options->set('option5', 'new_value5'));
		static::assertTrue($options->set('option7', 'new_value7'));

		// Verify selective invalidation
		$invalidated = [3, 5, 7];
		for ($i = 1; $i <= 10; $i++) {
			$cacheKey = SysOptions::class . "::get(option{$i})";
			if (in_array($i, $invalidated)) {
				static::assertFalse(Yii::$app->cache->get($cacheKey),
					"option{$i} cache should be invalidated");
			} else {
				static::assertNotFalse(Yii::$app->cache->get($cacheKey),
					"option{$i} cache should NOT be invalidated");
			}
		}
	}

	/**
	 * Verify drop() invalidates cache with TagDependency
	 *
	 * @throws Exception
	 */
	public function testDropInvalidatesCacheWithTagDependency():void {
		$options = new SysOptions();

		// Set and cache value
		static::assertTrue($options->set('test_option', 'test_value'));
		static::assertEquals('test_value', $options->get('test_option'));

		// Verify cache exists
		$cacheKey = SysOptions::class . "::get(test_option)";
		static::assertNotFalse(Yii::$app->cache->get($cacheKey), 'Cache should exist before drop()');

		// Drop option - should invalidate cache
		static::assertTrue($options->drop('test_option'));

		// Cache should be invalidated
		static::assertFalse(Yii::$app->cache->get($cacheKey),
			'Cache should be invalidated after drop()');

		// get() should return null (not cached value)
		static::assertNull($options->get('test_option'));
	}

	/**
	 * Verify multiple instances share the same cache via TagDependency
	 *
	 * @throws Exception
	 */
	public function testMultipleInstancesShareCache():void {
		$options1 = new SysOptions();

		$options2 = new SysOptions();

		// Instance 1 sets and caches value
		static::assertTrue($options1->set('shared_option', 'initial_value'));
		static::assertEquals('initial_value', $options1->get('shared_option'));

		// Instance 2 should read from same cache
		static::assertEquals('initial_value', $options2->get('shared_option'),
			'Second instance should read from shared cache');

		// Instance 2 updates value - should invalidate shared cache
		static::assertTrue($options2->set('shared_option', 'updated_value'));

		// Instance 1 should see updated value (cache was invalidated)
		static::assertEquals('updated_value', $options1->get('shared_option'),
			'First instance should see updated value after cache invalidation');
	}

	/**
	 * Verify cache invalidation works correctly with null values
	 *
	 * @throws Exception
	 */
	public function testCacheInvalidationWithNullValues():void {
		$options = new SysOptions();

		// Set non-null value
		static::assertTrue($options->set('test_option', 'initial_value'));
		static::assertEquals('initial_value', $options->get('test_option'));

		// Update to null
		static::assertTrue($options->set('test_option', null));

		// Should get null (not cached old value)
		static::assertNull($options->get('test_option'),
			'Should return null after updating to null, not cached old value');

		// Verify cache was properly invalidated and repopulated with null
		$cacheKey = SysOptions::class . "::get(test_option)";
		$cachedValue = Yii::$app->cache->get($cacheKey);
		static::assertNotFalse($cachedValue, 'Null value should be cached');
		static::assertEquals(serialize(null), $cachedValue, 'Cached value should be serialized null');
	}

	/**
	 * Verify non-existent options are NOT cached (only DB values are cached)
	 *
	 * @throws Exception
	 */
	public function testNonExistentOptionsNotCached():void {
		$options = new SysOptions();

		// Get non-existent option with default
		$result = $options->get('nonexistent', 'default_value');
		static::assertEquals('default_value', $result);

		// Cache should NOT contain this default value
		$cacheKey = SysOptions::class . "::get(nonexistent)";
		static::assertFalse(Yii::$app->cache->get($cacheKey),
			'Default values for non-existent options should not be cached');

		// Multiple calls should still return default (not cached)
		for ($i = 0; $i < 5; $i++) {
			static::assertEquals('default_value', $options->get('nonexistent', 'default_value'));
		}

		// Cache should still not exist
		static::assertFalse(Yii::$app->cache->get($cacheKey),
			'Default values should never be cached');
	}

	/**
	 * Verify manual TagDependency invalidation works
	 *
	 * @throws Exception
	 */
	public function testManualTagDependencyInvalidation():void {
		$options = new SysOptions();

		// Set and cache value
		static::assertTrue($options->set('test_option', 'test_value'));
		static::assertEquals('test_value', $options->get('test_option'));

		// Manually invalidate cache via TagDependency
		$tag = SysOptions::class . "::get(test_option)";
		TagDependency::invalidate(Yii::$app->cache, [$tag]);

		// Cache should be invalidated
		$cacheKey = SysOptions::class . "::get(test_option)";
		static::assertFalse(Yii::$app->cache->get($cacheKey),
			'Manual TagDependency invalidation should clear cache');

		// Next get() should read from DB and repopulate cache
		static::assertEquals('test_value', $options->get('test_option'));
		static::assertNotFalse(Yii::$app->cache->get($cacheKey),
			'Cache should be repopulated after invalidation');
	}

	/**
	 * Verify cache behavior when switching caching on/off
	 *
	 * @throws Exception
	 */
	public function testCacheBehaviorWithToggledCaching():void {
		// Start with caching enabled
		$options1 = new SysOptions();

		static::assertTrue($options1->set('test_option', 'cached_value'));
		static::assertEquals('cached_value', $options1->get('test_option'));

		// Verify cache exists
		$cacheKey = SysOptions::class . "::get(test_option)";
		static::assertNotFalse(Yii::$app->cache->get($cacheKey), 'Value should be cached');

		// Create new instance with caching disabled
		$options2 = new SysOptions(['cache' => null]);

		// Update value without caching
		static::assertTrue($options2->set('test_option', 'new_value'));

		// Old cache should still exist (wasn't invalidated because caching was disabled)
		static::assertNotFalse(Yii::$app->cache->get($cacheKey),
			'Cache should still exist when updated with caching disabled');

		// Instance with caching enabled would read stale cache
		$options3 = new SysOptions();

		// This demonstrates a potential issue: stale cache when toggling caching
		// However, this is expected behavior - if you disable caching, you're responsible for cache management
		static::assertEquals('cached_value', $options3->get('test_option'),
			'With caching enabled, reads stale cache (expected behavior when caching was toggled)');
	}

	/**
	 * Verify cache keys are properly formatted and unique per option
	 *
	 * @throws Exception
	 */
	public function testCacheKeyUniqueness():void {
		$options = new SysOptions();

		// Set options with similar names
		static::assertTrue($options->set('test', 'value1'));
		static::assertTrue($options->set('test_option', 'value2'));
		static::assertTrue($options->set('test_option_2', 'value3'));

		// Get all to populate cache
		static::assertEquals('value1', $options->get('test'));
		static::assertEquals('value2', $options->get('test_option'));
		static::assertEquals('value3', $options->get('test_option_2'));

		// Verify each has unique cache key
		$cacheKey1 = SysOptions::class . "::get(test)";
		$cacheKey2 = SysOptions::class . "::get(test_option)";
		$cacheKey3 = SysOptions::class . "::get(test_option_2)";

		static::assertNotEquals($cacheKey1, $cacheKey2, 'Cache keys should be unique');
		static::assertNotEquals($cacheKey2, $cacheKey3, 'Cache keys should be unique');

		// Verify all cached values are different
		$cached1 = Yii::$app->cache->get($cacheKey1);
		$cached2 = Yii::$app->cache->get($cacheKey2);
		$cached3 = Yii::$app->cache->get($cacheKey3);

		static::assertNotEquals($cached1, $cached2, 'Cached values should be different');
		static::assertNotEquals($cached2, $cached3, 'Cached values should be different');
	}
}
