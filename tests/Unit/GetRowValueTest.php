<?php
declare(strict_types = 1);

namespace Tests\Unit;

use Codeception\Test\Unit;
use pozitronik\sys_options\models\SysOptions;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use Tests\Support\UnitTester;

/**
 * PostgreSQL resource stream handling tests
 *
 * Unit tests for getRowValue() private static method using reflection.
 * This method handles PostgreSQL bytea columns that are returned as resource streams,
 * always returning string (serialized data from database).
 */
class GetRowValueTest extends Unit {

	protected UnitTester $tester;

	private ReflectionMethod $method;

	/**
	 * @return void
	 */
	protected function _before():void {
		// Get access to private static method via reflection
		$reflection = new ReflectionClass(SysOptions::class);
		$this->method = $reflection->getMethod('getRowValue');
	}

	/**
	 * Verify getRowValue() handles regular string value (typical case)
	 *
	 * @throws ReflectionException
	 */
	public function testGetRowValueWithRegularString():void {
		$serialized = serialize('test_value');
		$row = ['value' => $serialized];
		$result = $this->method->invoke(null, $row);

		static::assertEquals($serialized, $result);
		static::assertIsString($result);
	}

	/**
	 * Verify getRowValue() handles serialized array
	 *
	 * @throws ReflectionException
	 */
	public function testGetRowValueWithSerializedArray():void {
		$serialized = serialize(['key' => 'value', 'number' => 42]);
		$row = ['value' => $serialized];
		$result = $this->method->invoke(null, $row);

		static::assertEquals($serialized, $result);
		static::assertIsString($result);
	}

	/**
	 * Verify getRowValue() handles serialized null
	 *
	 * @throws ReflectionException
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
	 * Verify getRowValue() handles empty string
	 *
	 * @throws ReflectionException
	 */
	public function testGetRowValueWithEmptyString():void {
		$row = ['value' => ''];
		$result = $this->method->invoke(null, $row);

		static::assertEquals('', $result);
		static::assertIsString($result);
	}

	/**
	 * Verify getRowValue() handles simulated PostgreSQL resource stream
	 *
	 * PostgreSQL returns bytea columns as resource streams.
	 *
	 * @throws ReflectionException
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
	 * Verify getRowValue() handles binary data in stream
	 *
	 * @throws ReflectionException
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
	 * Verify getRowValue() handles large data in stream
	 *
	 * @throws ReflectionException
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
	 * Verify getRowValue() handles empty stream
	 *
	 * @throws ReflectionException
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
	 * Verify getRowValue() reads from stream beginning regardless of position
	 *
	 * Uses offset=0 in stream_get_contents() to always read from start.
	 *
	 * @throws ReflectionException
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
