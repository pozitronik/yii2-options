<?php
declare(strict_types = 1);

namespace Tests\Integration;

use Codeception\Test\Unit;
use pozitronik\sys_options\models\SysOptions;
use Tests\Support\Helper\MigrationHelper;
use Tests\Support\IntegrationTester;
use Throwable;
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
}
