<?php
declare(strict_types = 1);

namespace app\fixtures;

use app\models\Partners;
use yii\test\ActiveFixture;

/**
 * PartnersFixture
 */
class PartnersFixture extends ActiveFixture {
	public $modelClass = Partners::class;
}