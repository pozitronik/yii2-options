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
		static::assertFalse($options->cacheEnabled, 'Caching should be disabled when cache component is null');

		// These operations should work without exceptions (caching disabled)
		static::assertTrue($options->set('test_option', 'test_value'));
		static::assertEquals('test_value', $options->get('test_option'));
		static::assertTrue($options->drop('test_option'));
	}

	/**
	 * Verify manually enabling cache when component is null throws exception
	 *
	 * If user manually sets cacheEnabled=true with null cache component,
	 * cache operations should throw exception.
	 *
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
		$options = new SysOptions();
		$options->cacheEnabled = false;

		static::assertTrue($options->set('no_cache_test', 'test_value'));
		static::assertEquals('test_value', $options->get('no_cache_test'));
	}
}
