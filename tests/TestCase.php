<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Chốt chặn: test dùng RefreshDatabase (xoá sạch DB), chỉ được chạy trên SQLite trong bộ nhớ.
     * Container Docker có sẵn DB_CONNECTION=mysql; nếu phpunit.xml không ghi đè được thì dừng trước khi đụng dữ liệu thật.
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        $connection = $app['config']->get('database.default');
        if ($connection !== 'sqlite' || $app['config']->get("database.connections.{$connection}.database") !== ':memory:') {
            throw new RuntimeException("Dừng test: đang trỏ tới database thật ({$connection}). Test chỉ chạy trên SQLite :memory:.");
        }

        return $app;
    }
}
