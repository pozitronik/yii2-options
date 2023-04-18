<?php
declare(strict_types = 1);

namespace Tests\Unit;

use app\fixtures\BooksFixture;
use app\fixtures\PartnersFixture;
use app\fixtures\UsersFixture;
use Codeception\Test\Unit;
use Exception;
use Faker\Factory;
use pozitronik\sys_options\models\SysOptions;
use Tests\Support\Helper\MigrationHelper;
use Tests\Support\UnitTester;
use Throwable;
use Yii;
use yii\base\Application;
use yii\base\Exception as BaseException;
use yii\base\InvalidRouteException;

/**
 *
 */
class MainTest extends Unit {

	protected UnitTester $tester;

	/**
	 * @return string[]
	 * @throws Exception
	 * @throws InvalidRouteException
	 */
	public function _fixtures():array {
		MigrationHelper::migrateFresh(['migrationPath' => ['@app/migrations/', '@app/../../migrations']]);
		return ['users' => UsersFixture::class, 'books' => BooksFixture::class, 'partners' => PartnersFixture::class,];
	}

	/**
	 * @return void
	 */
	public function testSomeFeature():void {
		$this->tester->assertInstanceOf(Application::class, Yii::$app);
	}

	/**
	 * @return void
	 */
	public function testGetSet():void {

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
