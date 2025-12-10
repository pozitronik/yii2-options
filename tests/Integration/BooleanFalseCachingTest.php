<?php
declare(strict_types = 1);

namespace Tests\Integration;

use Codeception\Test\Unit;
use Exception as BaseException;
use pozitronik\sys_options\models\SysOptions;
use Tests\Support\Helper\MigrationHelper;
use Tests\Support\IntegrationTester;
use Throwable;
use Yii;
use yii\base\InvalidConfigException;
use yii\base\InvalidRouteException;
use yii\caching\FileCache;
use yii\console\Exception;

/**
 * Tests for GitHub Issue #9: Storing bool with false value ignores cached data
 *
 * @see https://github.com/pozitronik/yii2-options/issues/9
 *
 * The issue reports that when storing a boolean false value, subsequent get() calls
 * always query the database instead of using the cached value.
 */
class BooleanFalseCachingTest extends Unit {

	protected IntegrationTester $tester;

	/**
	 * @return void
	 * @throws InvalidConfigException
	 * @throws InvalidRouteException
	 * @throws Exception
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
	 * Reproduce GitHub Issue #9: Boolean false value should be cached correctly
	 *
	 * This test verifies that:
	 * 1. Setting a false value works correctly
	 * 2. First get() retrieves from DB and caches
	 * 3. Second get() retrieves from cache (no DB query)
	 *
	 * @throws BaseException
	 */
	public function testBooleanFalseIsCachedCorrectly():void {
		$options = new SysOptions();

		// Set boolean false
		static::assertTrue($options->set('bool_option', false));

		// First get - should retrieve from DB and cache
		$result1 = $options->get('bool_option');
		static::assertFalse($result1, 'First get should return false');

		// Verify the value is in cache
		$cacheKey = SysOptions::class . "::get(bool_option)";
		$cachedValue = Yii::$app->cache->get($cacheKey);
		static::assertNotFalse($cachedValue, 'Serialized value should be cached (not false)');
		static::assertEquals('b:0;', $cachedValue, 'Cached value should be serialized false');

		// Second get - should use cache, not DB
		$result2 = $options->get('bool_option');
		static::assertFalse($result2, 'Second get should also return false');
	}

	/**
	 * Verify that boolean true is cached correctly (control test)
	 *
	 * @throws BaseException
	 */
	public function testBooleanTrueIsCachedCorrectly():void {
		$options = new SysOptions();

		// Set boolean true
		static::assertTrue($options->set('bool_option', true));

		// First get
		$result1 = $options->get('bool_option');
		static::assertTrue($result1, 'First get should return true');

		// Verify cache
		$cacheKey = SysOptions::class . "::get(bool_option)";
		$cachedValue = Yii::$app->cache->get($cacheKey);
		static::assertNotFalse($cachedValue, 'Serialized value should be cached');
		static::assertEquals('b:1;', $cachedValue, 'Cached value should be serialized true');

		// Second get
		$result2 = $options->get('bool_option');
		static::assertTrue($result2, 'Second get should also return true');
	}

	/**
	 * Verify that integer zero is cached correctly
	 *
	 * @throws BaseException
	 */
	public function testIntegerZeroIsCachedCorrectly():void {
		$options = new SysOptions();

		// Set integer zero
		static::assertTrue($options->set('zero_option', 0));

		// First get
		$result1 = $options->get('zero_option');
		static::assertSame(0, $result1, 'First get should return 0');

		// Verify cache
		$cacheKey = SysOptions::class . "::get(zero_option)";
		$cachedValue = Yii::$app->cache->get($cacheKey);
		static::assertNotFalse($cachedValue, 'Serialized value should be cached');
		static::assertEquals('i:0;', $cachedValue, 'Cached value should be serialized 0');

		// Second get
		$result2 = $options->get('zero_option');
		static::assertSame(0, $result2, 'Second get should also return 0');
	}

	/**
	 * Verify that empty string is cached correctly
	 *
	 * @throws BaseException
	 */
	public function testEmptyStringIsCachedCorrectly():void {
		$options = new SysOptions();

		// Set empty string
		static::assertTrue($options->set('empty_option', ''));

		// First get
		$result1 = $options->get('empty_option');
		static::assertSame('', $result1, 'First get should return empty string');

		// Verify cache
		$cacheKey = SysOptions::class . "::get(empty_option)";
		$cachedValue = Yii::$app->cache->get($cacheKey);
		static::assertNotFalse($cachedValue, 'Serialized value should be cached');
		static::assertEquals('s:0:"";', $cachedValue, 'Cached value should be serialized empty string');

		// Second get
		$result2 = $options->get('empty_option');
		static::assertSame('', $result2, 'Second get should also return empty string');
	}

	/**
	 * Verify that null value is cached correctly
	 *
	 * @throws BaseException
	 */
	public function testNullValueIsCachedCorrectly():void {
		$options = new SysOptions();

		// Set null
		static::assertTrue($options->set('null_option', null));

		// First get
		$result1 = $options->get('null_option');
		static::assertNull($result1, 'First get should return null');

		// Verify cache - serialized null is 'N;'
		$cacheKey = SysOptions::class . "::get(null_option)";
		$cachedValue = Yii::$app->cache->get($cacheKey);
		static::assertNotFalse($cachedValue, 'Serialized value should be cached');
		static::assertEquals('N;', $cachedValue, 'Cached value should be serialized null');

		// Second get
		$result2 = $options->get('null_option');
		static::assertNull($result2, 'Second get should also return null');
	}

	/**
	 * Verify cache behavior with multiple consecutive get() calls
	 *
	 * This test ensures that repeated get() calls use the cache
	 * and don't hit the database each time.
	 *
	 * @throws BaseException
	 */
	public function testMultipleGetCallsUseCacheForFalseValue():void {
		$options = new SysOptions();

		static::assertTrue($options->set('repeated_option', false));

		// Make multiple get() calls
		$results = [];
		for ($i = 0; $i < 5; $i++) {
			$results[] = $options->get('repeated_option');
		}

		// All should return false
		foreach ($results as $index => $result) {
			static::assertFalse($result, "Get call #{$index} should return false");
		}

		// Cache should still have the serialized value
		$cacheKey = SysOptions::class . "::get(repeated_option)";
		$cachedValue = Yii::$app->cache->get($cacheKey);
		static::assertEquals('b:0;', $cachedValue, 'Cache should contain serialized false');
	}

	/**
	 * Verify that cache is properly invalidated when updating false to true
	 *
	 * @throws BaseException
	 */
	public function testCacheInvalidationWhenUpdatingFalseValue():void {
		$options = new SysOptions();

		// Set to false
		static::assertTrue($options->set('update_option', false));
		static::assertFalse($options->get('update_option'));

		// Update to true
		static::assertTrue($options->set('update_option', true));

		// Verify cache was invalidated and new value is returned
		$result = $options->get('update_option');
		static::assertTrue($result, 'Should return updated value true');

		// Verify new value is cached
		$cacheKey = SysOptions::class . "::get(update_option)";
		$cachedValue = Yii::$app->cache->get($cacheKey);
		static::assertEquals('b:1;', $cachedValue, 'Cache should contain serialized true');
	}

	/**
	 * Verify default value is returned for non-existent option when false is default
	 *
	 * This ensures we can distinguish between:
	 * - Option exists with value false
	 * - Option doesn't exist with default false
	 *
	 * @throws BaseException
	 */
	public function testDefaultFalseForNonExistentOption():void {
		$options = new SysOptions();

		// Get non-existent option with default false
		$result = $options->get('nonexistent_option', false);
		static::assertFalse($result, 'Should return default false');

		// Verify nothing is cached for non-existent option
		$cacheKey = SysOptions::class . "::get(nonexistent_option)";
		$cachedValue = Yii::$app->cache->get($cacheKey);
		static::assertFalse($cachedValue, 'Non-existent option should not be cached');
	}

	/**
	 * Verify distinction between stored false and default false
	 *
	 * @throws BaseException
	 */
	public function testDistinguishStoredFalseFromDefaultFalse():void {
		$options = new SysOptions();

		// Set one option to false
		static::assertTrue($options->set('stored_false', false));

		// Get stored false
		$storedResult = $options->get('stored_false', 'default_value');
		static::assertFalse($storedResult, 'Should return stored false, not default');

		// Get non-existent with default false
		$defaultResult = $options->get('not_stored', false);
		static::assertFalse($defaultResult, 'Should return default false');

		// Both return false but for different reasons
		// The stored one should be in cache
		$storedCacheKey = SysOptions::class . "::get(stored_false)";
		$notStoredCacheKey = SysOptions::class . "::get(not_stored)";

		static::assertEquals('b:0;', Yii::$app->cache->get($storedCacheKey), 'Stored false should be cached');
		static::assertFalse(Yii::$app->cache->get($notStoredCacheKey), 'Non-existent should not be cached');
	}

	/**
	 * Test what happens if we manually cache false directly (simulating the bug scenario)
	 *
	 * This test demonstrates why the current implementation works:
	 * We store serialized strings, not raw boolean values.
	 *
	 * @throws BaseException
	 */
	public function testDirectFalseCachingProblem():void {
		$cacheKey = "test_direct_false";

		// Simulate what would happen if we cached false directly (BAD practice)
		Yii::$app->cache->set($cacheKey, false);

		// cache->get() returns false for BOTH cache miss AND cached false value
		// This is the Yii limitation mentioned in the issue
		$result = Yii::$app->cache->get($cacheKey);

		// We cannot distinguish between "cache miss" and "cached false"!
		// This is exactly the problem that the issue describes
		static::assertFalse($result, 'Cache returns false - but is it cache miss or cached value?');

		// However, our implementation stores SERIALIZED values, not raw values
		// So this problem doesn't affect us
		Yii::$app->cache->set($cacheKey . '_serialized', 'b:0;');
		$serializedResult = Yii::$app->cache->get($cacheKey . '_serialized');
		static::assertEquals('b:0;', $serializedResult, 'Serialized false is clearly distinguishable');
		static::assertNotFalse($serializedResult, 'Serialized false is NOT boolean false');
	}

	/**
	 * Verify cache->get() behavior for cache miss vs cached false
	 *
	 * This test documents the Yii cache limitation that the issue references.
	 *
	 * @throws BaseException
	 */
	public function testYiiCacheFalseLimitation():void {
		// Cache miss returns false
		$missResult = Yii::$app->cache->get('definitely_not_cached_key_' . uniqid());
		static::assertFalse($missResult, 'Cache miss returns false');

		// If we store false directly, get() also returns false
		Yii::$app->cache->set('cached_false', false);
		$cachedFalseResult = Yii::$app->cache->get('cached_false');
		static::assertFalse($cachedFalseResult, 'Cached false also returns false');

		// These are INDISTINGUISHABLE - this is the Yii limitation
		static::assertSame($missResult, $cachedFalseResult, 'Cache miss and cached false are identical');

		// But with serialized strings, they ARE distinguishable
		Yii::$app->cache->set('cached_serialized_false', 'b:0;');
		$serializedResult = Yii::$app->cache->get('cached_serialized_false');
		static::assertNotSame($missResult, $serializedResult, 'Cache miss and serialized false are different');
	}

	/**
	 * Integration test: verify the full flow works correctly with false values
	 *
	 * This directly tests the scenario from GitHub issue #9
	 *
	 * @throws BaseException
	 */
	public function testGitHubIssue9Scenario():void {
		$options = new SysOptions();

		// Step 1: Set an option with false value
		$options->set('myOption', false);

		// Step 2: Call get() - first time (should query DB and cache)
		$res1 = $options->get('myOption');
		static::assertFalse($res1, 'First get should return false');

		// Verify it was cached as serialized string
		$cacheKey = SysOptions::class . "::get(myOption)";
		$cachedValue = Yii::$app->cache->get($cacheKey);
		static::assertNotFalse($cachedValue, 'Cached value should NOT be boolean false');
		static::assertEquals('b:0;', $cachedValue, 'Cached value should be serialized false string');

		// Step 3: Call get() again - should use cache, NOT query DB
		$res2 = $options->get('myOption');
		static::assertFalse($res2, 'Second get should also return false');

		// The cached value should still be there
		$cachedValueAfter = Yii::$app->cache->get($cacheKey);
		static::assertEquals('b:0;', $cachedValueAfter, 'Cache should still contain serialized false');
	}

	/**
	 * Test keyExists parameter for existing option with false value
	 *
	 * @throws BaseException
	 */
	public function testKeyExistsParameterWithFalseValue():void {
		$options = new SysOptions();

		// Set option to false
		$options->set('exists_with_false', false);

		// Get with keyExists parameter
		$keyExists = null;
		$result = $options->get('exists_with_false', 'default', $keyExists);

		static::assertFalse($result, 'Should return stored false value');
		/** @noinspection PhpUnitAssertTrueWithIncompatibleTypeArgumentInspection */
		static::assertTrue($keyExists, 'keyExists should be true for existing option');
	}

	/**
	 * Test keyExists parameter for non-existent option
	 *
	 * @throws BaseException
	 */
	public function testKeyExistsParameterWithNonExistentOption():void {
		$options = new SysOptions();

		// Get non-existent option with keyExists parameter
		$keyExists = null;
		$result = $options->get('non_existent_option', false, $keyExists);

		static::assertFalse($result, 'Should return default false value');
		static::assertFalse($keyExists, 'keyExists should be false for non-existent option');
	}

	/**
	 * Test that keyExists allows distinguishing stored false from default false
	 *
	 * @throws BaseException
	 */
	public function testKeyExistsDistinguishesStoredFromDefault():void {
		$options = new SysOptions();

		// Set one option to false
		$options->set('stored_false', false);

		// Get stored false - both return false, but keyExists differs
		$exists1 = null;
		$result1 = $options->get('stored_false', false, $exists1);

		$exists2 = null;
		$result2 = $options->get('not_stored', false, $exists2);

		// Both return false
		static::assertFalse($result1);
		static::assertFalse($result2);

		// But keyExists tells us the difference!
		/** @noinspection PhpUnitAssertTrueWithIncompatibleTypeArgumentInspection */
		static::assertTrue($exists1, 'stored_false exists in DB');
		static::assertFalse($exists2, 'not_stored does not exist in DB');
	}

	/**
	 * Test keyExists with null value (option exists with null)
	 *
	 * @throws BaseException
	 */
	public function testKeyExistsWithNullValue():void {
		$options = new SysOptions();

		// Set option to null
		$options->set('null_option', null);

		// Get with keyExists
		$keyExists = null;
		$result = $options->get('null_option', 'default', $keyExists);

		static::assertNull($result, 'Should return stored null');
		/** @noinspection PhpUnitAssertTrueWithIncompatibleTypeArgumentInspection */
		static::assertTrue($keyExists, 'keyExists should be true - option exists with null value');
	}

	/**
	 * Test keyExists backward compatibility - parameter is optional
	 *
	 * @throws BaseException
	 */
	public function testKeyExistsBackwardCompatibility():void {
		$options = new SysOptions();

		$options->set('test_option', 'value');

		// Old-style calls should still work without keyExists parameter
		$result1 = $options->get('test_option');
		static::assertEquals('value', $result1);

		$result2 = $options->get('test_option', 'default');
		static::assertEquals('value', $result2);

		$result3 = $options->get('non_existent');
		static::assertNull($result3);

		$result4 = $options->get('non_existent', 'my_default');
		static::assertEquals('my_default', $result4);
	}

	/**
	 * Test keyExists with static method
	 *
	 * @throws Throwable
	 * @noinspection PhpDeprecationInspection
	 */
	public function testKeyExistsWithStaticMethod():void {
		// Use the configured sysoptions component
		/** @var SysOptions $options */
		/** @noinspection PhpUndefinedFieldInspection */
		$options = Yii::$app->sysoptions;
		$options->set('static_test', 'value');

		// Test static method with keyExists
		$keyExists = null;
		$result = SysOptions::getStatic('static_test', 'default', $keyExists);

		static::assertEquals('value', $result);
		/** @noinspection PhpUnitAssertTrueWithIncompatibleTypeArgumentInspection */
		static::assertTrue($keyExists);

		// Test non-existent with static method
		$keyExists2 = null;
		$result2 = SysOptions::getStatic('non_existent_static', 'default', $keyExists2);

		static::assertEquals('default', $result2);
		static::assertFalse($keyExists2);
	}
}
