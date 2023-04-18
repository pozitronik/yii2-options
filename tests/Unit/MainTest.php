<?php
declare(strict_types = 1);

namespace Tests\Unit;

use Codeception\Test\Unit;
use pozitronik\sys_options\models\SysOptions;
use Tests\Support\Helper\MigrationHelper;
use Tests\Support\UnitTester;
use Throwable;
use Yii;
use yii\base\Application;
use yii\base\Exception as BaseException;

/**
 *
 */
class MainTest extends Unit {

	protected UnitTester $tester;

	/**
	 * @Override
	 */
	protected function _before():void {
		MigrationHelper::migrateFresh(['migrationPath' => ['@app/migrations/', '@app/../../migrations']]);
	}

	/**
	 * @return void
	 */
	public function testSomeFeature():void {
		$this->tester->assertInstanceOf(Application::class, Yii::$app);
	}

	/**
	 * @return void
	 * @throws BaseException
	 */
	public function testGetSet():void {
		$options = new SysOptions();
		$randomString = Yii::$app->security->generateRandomString();
		$randomInt = random_int(PHP_INT_MIN, PHP_INT_MAX);
		$randomFloat = random_int(PHP_INT_MIN, PHP_INT_MAX) / random_int(PHP_INT_MIN, PHP_INT_MAX);
		$randomArray = array_map(static function() {
			return match (random_int(1, 3)) {
				1 => Yii::$app->security->generateRandomString(),
				2 => random_int(PHP_INT_MIN, PHP_INT_MAX),
				3 => random_int(PHP_INT_MIN, PHP_INT_MAX) / random_int(PHP_INT_MIN, PHP_INT_MAX),
			};
		}, range(1, random_int(1, 100)));

		static::assertTrue($options->set('string', $randomString));
		static::assertTrue($options->set('int', $randomInt));
		static::assertTrue($options->set('float', $randomFloat));
		static::assertTrue($options->set('array', $randomArray));

		static::assertEquals($randomString, $options->get('string'));
		static::assertEquals($randomInt, $options->get('int'));
		static::assertEquals($randomFloat, $options->get('float'));
		static::assertEquals($randomArray, $options->get('array'));
	}

	/**
	 * @return void
	 * @throws Throwable
	 * @throws BaseException
	 */
	public function testGetSetStatic():void {
		$randomString = Yii::$app->security->generateRandomString();
		$randomInt = random_int(PHP_INT_MIN, PHP_INT_MAX);
		$randomFloat = random_int(PHP_INT_MIN, PHP_INT_MAX) / random_int(PHP_INT_MIN, PHP_INT_MAX);
		$randomArray = array_map(static function() {
			return match (random_int(1, 3)) {
				1 => Yii::$app->security->generateRandomString(),
				2 => random_int(PHP_INT_MIN, PHP_INT_MAX),
				3 => random_int(PHP_INT_MIN, PHP_INT_MAX) / random_int(PHP_INT_MIN, PHP_INT_MAX),
			};
		}, range(1, random_int(1, 100)));

		static::assertTrue(SysOptions::setStatic('string', $randomString));
		static::assertTrue(SysOptions::setStatic('int', $randomInt));
		static::assertTrue(SysOptions::setStatic('float', $randomFloat));
		static::assertTrue(SysOptions::setStatic('array', $randomArray));

		static::assertEquals($randomString, SysOptions::getStatic('string'));
		static::assertEquals($randomInt, SysOptions::getStatic('int'));
		static::assertEquals($randomFloat, SysOptions::getStatic('float'));
		static::assertEquals($randomArray, SysOptions::getStatic('array'));
	}
}
