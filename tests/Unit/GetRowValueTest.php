<?php
declare(strict_types = 1);

namespace Tests\Unit;

use Codeception\Test\Unit;
use pozitronik\sys_options\models\SysOptions;
use ReflectionClass;
use ReflectionMethod;
use Tests\Support\UnitTester;

/**
 * Tests for getRowValue() private static method
 *
 * Tests PostgreSQL resource stream handling.
 * Method always returns string (serialized data from database).
 */
class GetRowValueTest extends Unit {

	protected UnitTester $tester;

	private ReflectionMethod $method;

	/**
	 * @Override
	 */
	protected function _before():void {
		// Get access to private static method via reflection
		$reflection = new ReflectionClass(SysOptions::class);
		$this->method = $reflection->getMethod('getRowValue');
		$this->method->setAccessible(true);
	}

	/**
	 * Test getRowValue() with regular string value (typical case)
	 *
	 * @return void
	 * @throws \ReflectionException
	 */
	public function testGetRowValueWithRegularString():void {
		$serialized = serialize('test_value');
		$row = ['value' => $serialized];
		$result = $this->method->invoke(null, $row);

		static::assertEquals($serialized, $result);
		static::assertIsString($result);
	}

	/**
	 * Test getRowValue() with serialized array
	 *
	 * @return void
	 * @throws \ReflectionException
	 */
	public function testGetRowValueWithSerializedArray():void {
		$serialized = serialize(['key' => 'value', 'number' => 42]);
		$row = ['value' => $serialized];
		$result = $this->method->invoke(null, $row);

		static::assertEquals($serialized, $result);
		static::assertIsString($result);
	}

	/**
	 * Test getRowValue() with serialized null
	 *
	 * @return void
	 * @throws \ReflectionException
	 */
	public function testGetRowValueWithSerializedNull():void {
		$serialized = serialize(null);
		$row = ['value' => $serialized];
		$result = $this->method->invoke(null, $row);

		static::assertEquals($serialized, $result);
		static::assertIsString($result);
		static::assertEquals('N;', $result); // Serialized null is 'N;'
	}

	/**
	 * Test getRowValue() with empty string
	 *
	 * @return void
	 * @throws \ReflectionException
	 */
	public function testGetRowValueWithEmptyString():void {
		$row = ['value' => ''];
		$result = $this->method->invoke(null, $row);

		static::assertEquals('', $result);
		static::assertIsString($result);
	}

	/**
	 * Test getRowValue() with simulated PostgreSQL resource stream
	 *
	 * PostgreSQL returns bytea columns as resource streams.
	 *
	 * @return void
	 * @throws \ReflectionException
	 */
	public function testGetRowValueWithResourceStream():void {
		// Create a stream resource to simulate PostgreSQL behavior
		$serializedData = serialize(['postgres' => true]);
		$stream = fopen('php://memory', 'rb+');
		fwrite($stream, $serializedData);
		rewind($stream);

		$row = ['value' => $stream];
		$result = $this->method->invoke(null, $row);

		// Should extract content from stream as string
		static::assertEquals($serializedData, $result);
		static::assertIsString($result);

		fclose($stream);
	}

	/**
	 * Test getRowValue() with binary data in stream
	 *
	 * @return void
	 * @throws \ReflectionException
	 */
	public function testGetRowValueWithBinaryDataInStream():void {
		// Simulate binary serialized data
		$binaryData = serialize(['binary' => "\x00\x01\x02\xFF"]);
		$stream = fopen('php://memory', 'rb+');
		fwrite($stream, $binaryData);
		rewind($stream);

		$row = ['value' => $stream];
		$result = $this->method->invoke(null, $row);

		static::assertEquals($binaryData, $result);
		static::assertIsString($result);

		fclose($stream);
	}

	/**
	 * Test getRowValue() with large data in stream
	 *
	 * @return void
	 * @throws \ReflectionException
	 */
	public function testGetRowValueWithLargeStreamData():void {
		// Create large serialized data
		$largeArray = array_fill(0, 1000, 'test_string');
		$largeData = serialize($largeArray);

		$stream = fopen('php://memory', 'rb+');
		fwrite($stream, $largeData);
		rewind($stream);

		$row = ['value' => $stream];
		$result = $this->method->invoke(null, $row);

		static::assertEquals($largeData, $result);
		static::assertIsString($result);
		static::assertGreaterThan(10000, strlen($result));

		fclose($stream);
	}

	/**
	 * Test getRowValue() with empty stream
	 *
	 * @return void
	 * @throws \ReflectionException
	 */
	public function testGetRowValueWithEmptyStream():void {
		$stream = fopen('php://memory', 'rb+');
		// Don't write anything

		$row = ['value' => $stream];
		$result = $this->method->invoke(null, $row);

		static::assertEquals('', $result);
		static::assertIsString($result);

		fclose($stream);
	}

	/**
	 * Test getRowValue() reads from stream beginning regardless of position
	 *
	 * Uses offset=0 in stream_get_contents() to always read from start.
	 *
	 * @return void
	 * @throws \ReflectionException
	 */
	public function testGetRowValueReadsFromStreamBeginning():void {
		$content = serialize('complete_data');
		$stream = fopen('php://memory', 'rb+');
		fwrite($stream, $content);

		// Move pointer to middle
		fseek($stream, 10);

		$row = ['value' => $stream];
		$result = $this->method->invoke(null, $row);

		// Should read entire content from beginning (offset=0)
		static::assertEquals($content, $result);
		static::assertIsString($result);

		fclose($stream);
	}
}
