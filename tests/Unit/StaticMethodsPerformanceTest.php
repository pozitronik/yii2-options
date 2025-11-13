<?php
declare(strict_types = 1);

namespace Tests\Unit;

use Codeception\Test\Unit;
use Exception;
use pozitronik\sys_options\models\SysOptions;
use Tests\Support\Helper\MigrationHelper;
use Tests\Support\UnitTester;
use Throwable;
use Yii;
use yii\base\InvalidConfigException;

/**
 * Tests for static methods performance optimization (Issue #2)
 */
class StaticMethodsPerformanceTest extends Unit {

	protected UnitTester $tester;

	/**
	 * @Override
	 */
	protected function _before():void {
		MigrationHelper::migrateFresh(['migrationPath' => ['@app/migrations/', '@app/../../migrations']]);
	}

	/**
	 * Verify static methods throw exception without component configured
	 * @return void
	 * @throws InvalidConfigException
	 * @throws Throwable
	 */
	public function testStaticMethodsWithoutComponentConfigurationThrowsException():void {
		// Ensure component is not configured
		Yii::$app->set('sysoptions', null);

		// Static methods should throw exception
		$this->expectException(Exception::class);
		$this->expectExceptionMessage('SysOptions component is not configured');

		SysOptions::getStatic('test_option');
	}

	/**
	 * Verify static methods work with component configured
	 * @return void
	 * @throws InvalidConfigException
	 * @throws Throwable
	 */
	public function testStaticMethodsWithComponentConfiguration():void {
		// Configure component
		Yii::$app->set('sysoptions', [
			'class' => SysOptions::class,
		]);

		// Static methods should work
		static::assertTrue(SysOptions::setStatic('test_option', 'test_value'));
		static::assertEquals('test_value', SysOptions::getStatic('test_option'));
		static::assertTrue(SysOptions::dropStatic('test_option'));
	}

	/**
	 * Verify that with component configured, same instance is reused
	 * @return void
	 * @throws Exception
	 */
	public function testComponentConfigurationReusesSameInstance():void {
		// Configure component
		Yii::$app->set('sysoptions', [
			'class' => SysOptions::class,
		]);

		// Get instance via service locator
		$instance1 = Yii::$app->get('sysoptions');
		$instance2 = Yii::$app->get('sysoptions');

		// Should be the same instance
		static::assertSame($instance1, $instance2, 'Service locator should return same instance');
	}

	/**
	 * Verify component configuration with custom parameters
	 * @return void
	 * @throws InvalidConfigException
	 * @throws Throwable
	 */
	public function testComponentConfigurationWithCustomParameters():void {
		// Configure component with custom serializer
		Yii::$app->set('sysoptions', [
			'class' => SysOptions::class,
			'serializer' => [
				0 => fn($value) => json_encode($value),
				1 => fn(string $value) => json_decode($value, true),
			],
		]);

		// Test with array data (JSON serializer)
		$testData = ['key' => 'value', 'number' => 42];
		static::assertTrue(SysOptions::setStatic('json_option', $testData));
		static::assertEquals($testData, SysOptions::getStatic('json_option'));
	}

	/**
	 * Verify that component instance persists across multiple operations
	 * @return void
	 * @throws Throwable
	 * @throws InvalidConfigException
	 */
	public function testComponentInstancePersistsAcrossOperations():void {
		// Configure component
		Yii::$app->set('sysoptions', [
			'class' => SysOptions::class,
		]);

		// Multiple static calls should use same cached instance
		static::assertTrue(SysOptions::setStatic('test1', 'value1'));
		static::assertTrue(SysOptions::setStatic('test2', 'value2'));
		static::assertEquals('value1', SysOptions::getStatic('test1'));
		static::assertEquals('value2', SysOptions::getStatic('test2'));
		static::assertTrue(SysOptions::dropStatic('test1'));
		static::assertNull(SysOptions::getStatic('test1'));
	}
}
