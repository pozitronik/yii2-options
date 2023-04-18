<?php
declare(strict_types = 1);

namespace app\fixtures;

use app\models\Books;
use yii\test\ActiveFixture;

/**
 * BooksFixture
 */
class BooksFixture extends ActiveFixture {
	public $modelClass = Books::class;
}