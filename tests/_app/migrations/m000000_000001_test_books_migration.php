<?php
declare(strict_types = 1);

use yii\db\Migration;

/**
 * Class m000000_000000_test_books_migration
 */
class m000000_000001_test_books_migration extends Migration {

	/**
	 * {@inheritdoc}
	 */
	public function safeUp() {
		$this->createTable('books', [
			'id' => $this->primaryKey(),
			'name' => $this->string(255)->notNull()->comment('Название книги'),
			'author' => $this->string(64)->notNull()->comment('Автор'),
		]);
	}

	/**
	 * {@inheritdoc}
	 */
	public function safeDown() {
		$this->dropTable('books');
	}

}
