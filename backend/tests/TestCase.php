<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected bool $authenticate = false;

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->authenticate) {
            $this->actingAsAdmin();
        }
    }

    protected function actingAsAdmin(): User
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user, 'sanctum');

        return $user;
    }
}
