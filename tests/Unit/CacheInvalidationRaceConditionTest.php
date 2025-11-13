<?php
declare(strict_types = 1);

namespace Tests\Unit;

use Codeception\Test\Unit;
use Exception;
use pozitronik\sys_options\models\SysOptions;
use Tests\Support\Helper\MigrationHelper;
use Tests\Support\UnitTester;
use Yii;
use yii\caching\FileCache;

/**
 * Tests for cache invalidation race conditions (Issues #3 and #4)
 */
class CacheInvalidationRaceConditionTest extends Unit {

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
	 * Test Issue #3: Cache should be invalidated AFTER successful DB write in set()
	 *
	 * Expected behavior:
	 * 1. applyDbValue() is called FIRST
	 * 2. If it succeeds, THEN TagDependency::invalidate() is called
	 * 3. If applyDbValue() fails, cache is NOT invalidated
	 *
	 * This test will FAIL with current implementation and PASS after fix.
	 *
	 * @return void
	 */
	public function testSetInvalidatesCacheAfterSuccessfulDbWrite():void {
		// Read the actual source code to verify correct behavior
		$sourceCode = file_get_contents(__DIR__ . '/../../src/models/SysOptions.php');

		// Find the set() method
		preg_match('/public function set\(.*?\):bool \{(.*?)\n\t}/s', $sourceCode, $matches);
		$setMethodBody = $matches[1] ?? '';

		// Verify that applyDbValue appears BEFORE TagDependency::invalidate
		$applyDbPos = strpos($setMethodBody, 'applyDbValue');
		$invalidatePos = strpos($setMethodBody, 'TagDependency::invalidate');

		static::assertNotFalse($applyDbPos, 'applyDbValue should exist in set() method');
		static::assertNotFalse($invalidatePos, 'TagDependency::invalidate should exist in set() method');

		// CORRECT: DB write should happen before cache invalidation
		static::assertLessThan($invalidatePos, $applyDbPos,
			'DB write (applyDbValue) should happen BEFORE cache invalidation in set()');

		// Verify that invalidation is conditional on success
		// Should have pattern: if ($result && $this->cacheEnabled)
		static::assertStringContainsString('$result', $setMethodBody,
			'Cache invalidation should be conditional on DB write result');
	}

	/**
	 * Test Issue #4: Cache should be invalidated AFTER successful DB delete in drop()
	 *
	 * Expected behavior:
	 * 1. removeDbValue() is called FIRST
	 * 2. If it succeeds, THEN TagDependency::invalidate() is called
	 * 3. If removeDbValue() fails, cache is NOT invalidated
	 *
	 * This test will FAIL with current implementation and PASS after fix.
	 *
	 * @return void
	 */
	public function testDropInvalidatesCacheAfterSuccessfulDbDelete():void {
		// Read the actual source code to verify correct behavior
		$sourceCode = file_get_contents(__DIR__ . '/../../src/models/SysOptions.php');

		// Find the drop() method
		preg_match('/public function drop\(.*?\):bool \{(.*?)\n\t}/s', $sourceCode, $matches);
		$dropMethodBody = $matches[1] ?? '';

		// Verify that removeDbValue appears BEFORE TagDependency::invalidate
		$removeDbPos = strpos($dropMethodBody, 'removeDbValue');
		$invalidatePos = strpos($dropMethodBody, 'TagDependency::invalidate');

		static::assertNotFalse($removeDbPos, 'removeDbValue should exist in drop() method');
		static::assertNotFalse($invalidatePos, 'TagDependency::invalidate should exist in drop() method');

		// CORRECT: DB delete should happen before cache invalidation
		static::assertLessThan($invalidatePos, $removeDbPos,
			'DB delete (removeDbValue) should happen BEFORE cache invalidation in drop()');

		// Verify that invalidation is conditional on success
		// Should have pattern: if ($result && $this->cacheEnabled)
		static::assertStringContainsString('$result', $dropMethodBody,
			'Cache invalidation should be conditional on DB delete result');
	}

	/**
	 * Test that successful set() does invalidate cache
	 * This verifies that after the fix, cache invalidation still works
	 *
	 * @return void
	 * @throws Exception
	 */
	public function testSuccessfulSetInvalidatesCache():void {
		$options = new SysOptions();
		$options->cacheEnabled = true;

		// Set initial value
		static::assertTrue($options->set('test_option', 'initial_value'));

		// Get value to populate cache
		static::assertEquals('initial_value', $options->get('test_option'));

		// Update value - should succeed
		static::assertTrue($options->set('test_option', 'updated_value'));

		// Get value again - should return updated value, not cached old value
		static::assertEquals('updated_value', $options->get('test_option'),
			'Cache should be invalidated after successful set()');
	}

	/**
	 * Test that successful drop() does invalidate cache
	 * This verifies that after the fix, cache invalidation still works
	 *
	 * @return void
	 * @throws Exception
	 */
	public function testSuccessfulDropInvalidatesCache():void {
		$options = new SysOptions();
		$options->cacheEnabled = true;

		// Set initial value
		static::assertTrue($options->set('test_option', 'test_value'));

		// Get value to populate cache
		static::assertEquals('test_value', $options->get('test_option'));

		// Drop option - should succeed
		static::assertTrue($options->drop('test_option'));

		// Get value again - should return null, not cached value
		static::assertNull($options->get('test_option'),
			'Cache should be invalidated after successful drop()');
	}
}
