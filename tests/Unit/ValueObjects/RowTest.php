<?php

namespace InFlow\Tests\Unit\ValueObjects;

use InFlow\Tests\TestCase;
use InFlow\ValueObjects\Row;

class RowTest extends TestCase
{
    public function test_row_can_be_created_with_data_and_line_number(): void
    {
        $row = new Row(['name' => 'Test', 'age' => 30], 1);

        $this->assertEquals(['name' => 'Test', 'age' => 30], $row->data);
        $this->assertEquals(1, $row->lineNumber);
    }

    public function test_row_can_get_column_value(): void
    {
        $row = new Row(['name' => 'Test'], 1);

        $this->assertEquals('Test', $row->get('name'));
        $this->assertNull($row->get('missing'));
    }

    public function test_row_can_check_if_column_exists(): void
    {
        $row = new Row(['name' => 'Test'], 1);

        $this->assertTrue($row->has('name'));
        $this->assertFalse($row->has('missing'));
    }

    public function test_row_can_convert_to_array(): void
    {
        $data = ['name' => 'Test', 'age' => 30];
        $row = new Row($data, 1);

        $this->assertEquals($data, $row->toArray());
    }
}
