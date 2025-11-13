<?php
declare(strict_types = 1);

namespace Tests\Unit;

use Codeception\Test\Unit;
use Exception;
use pozitronik\sys_options\models\SysOptions;
use Tests\Support\Helper\MigrationHelper;
use Tests\Support\UnitTester;
use Throwable;
use TypeError;
use Yii;
use yii\base\InvalidConfigException;
use yii\caching\FileCache;
use pozitronik\sys_options\SysOptionsModule;

/**
 * Tests for Issue #15: No Cache TTL Configuration
 *
 * Verifies that cacheDuration parameter works correctly with various values.
 */
class CacheDurationTest extends Unit {

	protected UnitTester $tester;

	/**
	 * @Override
	 */
	protected function _before():void {
		MigrationHelper::migrateFresh(['migrationPath' => ['@app/migrations/', '@app/../../migrations']]);

		// Configure real cache for these tests
		Yii::$app->set('cache', [
			'class' => FileCache::class,
		]);
	}

	/**
	 * Test default cacheDuration is null (infinite caching - backward compatibility)
	 *
	 * @return void
	 * @throws Exception
	 */
	public function testDefaultCacheDurationIsNull():void {
		$options = new SysOptions();
		static::assertNull($options->cacheDuration, 'Default cacheDuration should be null for backward compatibility');
	}

	/**
	 * Test infinite caching with cacheDuration = null (default behavior)
	 *
	 * @return void
	 * @throws Exception
	 */
	public function testInfiniteCachingWithNullDuration():void {
		$options = new SysOptions();
		$options->cacheEnabled = true;
		$options->cacheDuration = null;

		// Set and get value
		static::assertTrue($options->set('test_option', 'test_value'));
		static::assertEquals('test_value', $options->get('test_option'));

		// Value should be cached and retrievable even after long delay (simulated by checking cache directly)
		$cacheKey = SysOptions::class . "::get(test_option)";
		$cachedValue = Yii::$app->cache->get($cacheKey);
		static::assertNotFalse($cachedValue, 'Value should be cached with infinite duration');
	}

	/**
	 * Test cacheDuration with positive integer (TTL in seconds)
	 *
	 * @return void
	 * @throws Exception
	 */
	public function testCacheDurationWithPositiveInteger():void {
		$options = new SysOptions();
		$options->cacheEnabled = true;
		$options->cacheDuration = 3600; // 1 hour

		// Set and get value
		static::assertTrue($options->set('test_option', 'test_value'));
		static::assertEquals('test_value', $options->get('test_option'));

		// Value should be cached
		$cacheKey = SysOptions::class . "::get(test_option)";
		$cachedValue = Yii::$app->cache->get($cacheKey);
		static::assertNotFalse($cachedValue, 'Value should be cached with TTL');
	}

	/**
	 * Test cacheDuration = 0 (effectively no caching, but doesn't disable cacheEnabled)
	 *
	 * @return void
	 * @throws Exception
	 */
	public function testCacheDurationZero():void {
		$options = new SysOptions();
		$options->cacheEnabled = true;
		$options->cacheDuration = 0;

		// Set and get value
		static::assertTrue($options->set('test_option', 'test_value'));
		static::assertEquals('test_value', $options->get('test_option'));

		// With duration=0, cache expires immediately
		// Note: Yii2 cache with duration=0 means "cache until end of request" or immediate expiry depending on backend
	}

	/**
	 * Test cacheDuration can be read from module configuration
	 *
	 * @return void
	 * @throws Exception
	 */
	public function testCacheDurationFromModuleConfiguration():void {
		// Configure module with custom cacheDuration
		Yii::$app->setModule('sysoptions', [
			'class' => SysOptionsModule::class,
			'params' => [
				'cacheDuration' => 7200, // 2 hours
			],
		]);

		$options = new SysOptions();
		static::assertEquals(7200, $options->cacheDuration, 'cacheDuration should be read from module configuration');
	}

	/**
	 * Test invalid cacheDuration (negative integer) throws exception
	 *
	 * @return void
	 */
	public function testInvalidCacheDurationNegativeThrowsException():void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessage('cacheDuration must be null or a non-negative integer (seconds)');

		$options = new SysOptions();
		$options->cacheDuration = -100;
		$options->init();
	}

	/**
	 * Test invalid cacheDuration (non-integer) throws TypeError due to strict typing in PHP 8.4
	 *
	 * PHP 8.4's strict property typing prevents assigning non-integer to ?int property.
	 * This is caught by the type system before our validation code runs.
	 *
	 * @return void
	 */
	public function testInvalidCacheDurationNonIntegerThrowsTypeError():void {
		$this->expectException(TypeError::class);

		$options = new SysOptions();
		/** @noinspection PhpStrictTypeCheckingInspection */
		$options->cacheDuration = 'invalid'; // TypeError on assignment
	}

	/**
	 * Test that TagDependency invalidation works independently of cacheDuration
	 *
	 * Even with long cacheDuration, set() should invalidate cache immediately.
	 *
	 * @return void
	 * @throws Exception
	 */
	public function testTagDependencyInvalidationWorksWithCacheDuration():void {
		$options = new SysOptions();
		$options->cacheEnabled = true;
		$options->cacheDuration = 86400; // 24 hours

		// Set initial value
		static::assertTrue($options->set('test_option', 'initial_value'));

		// Get to populate cache
		static::assertEquals('initial_value', $options->get('test_option'));

		// Update value - should invalidate cache despite long TTL
		static::assertTrue($options->set('test_option', 'updated_value'));

		// Should get updated value, not cached old value
		static::assertEquals('updated_value', $options->get('test_option'),
			'TagDependency should invalidate cache regardless of cacheDuration');
	}

	/**
	 * Test that drop() invalidates cache independently of cacheDuration
	 *
	 * @return void
	 * @throws Exception
	 */
	public function testDropInvalidatesCacheWithCacheDuration():void {
		$options = new SysOptions();
		$options->cacheEnabled = true;
		$options->cacheDuration = 86400; // 24 hours

		// Set value
		static::assertTrue($options->set('test_option', 'test_value'));

		// Get to populate cache
		static::assertEquals('test_value', $options->get('test_option'));

		// Drop option - should invalidate cache despite long TTL
		static::assertTrue($options->drop('test_option'));

		// Should return null, not cached value
		static::assertNull($options->get('test_option'),
			'drop() should invalidate cache regardless of cacheDuration');
	}

	/**
	 * Test cacheDuration with component configuration (static methods)
	 *
	 * @return void
	 * @throws Throwable
	 * @throws InvalidConfigException
	 */
	public function testCacheDurationWithComponentConfiguration():void {
		// Configure component with custom cacheDuration
		Yii::$app->set('sysoptions', [
			'class' => SysOptions::class,
			'cacheDuration' => 1800, // 30 minutes
		]);

		// Static methods should use configured cacheDuration
		static::assertTrue(SysOptions::setStatic('test_option', 'test_value'));
		static::assertEquals('test_value', SysOptions::getStatic('test_option'));

		// Verify cacheDuration was applied
		$instance = Yii::$app->get('sysoptions');
		static::assertEquals(1800, $instance->cacheDuration);
	}

	/**
	 * Test that cacheDuration doesn't affect behavior when caching is disabled
	 *
	 * When cacheEnabled=false, get() method should always read from database,
	 * regardless of cacheDuration value.
	 *
	 * @return void
	 * @throws Exception
	 */
	public function testCacheDurationIgnoredWhenCachingDisabled():void {
		// First, populate with caching enabled
		$options1 = new SysOptions();
		$options1->cacheEnabled = true;
		$options1->cacheDuration = 3600;
		static::assertTrue($options1->set('test_option', 'cached_value'));
		static::assertEquals('cached_value', $options1->get('test_option'));

		// Change value directly in DB (bypassing cache)
		static::assertTrue($options1->set('test_option', 'new_value'));

		// Create new instance with caching disabled
		$options2 = new SysOptions();
		$options2->cacheEnabled = false;
		$options2->cacheDuration = 3600; // This should be ignored

		// Should read from DB, not cache (even though cacheDuration is set)
		static::assertEquals('new_value', $options2->get('test_option'),
			'With cacheEnabled=false, should read from DB regardless of cacheDuration');
	}
}
