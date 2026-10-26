<?php

namespace Tests\Feature;

use App\Models\MasterRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeedIfEmptyTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_when_database_is_empty(): void
    {
        $this->artisan('app:seed-if-empty')->assertSuccessful();

        $this->assertSame(3, User::count());
        $this->assertSame(212, MasterRecord::count());
    }

    public function test_does_nothing_when_already_seeded(): void
    {
        $this->artisan('app:seed-if-empty')->assertSuccessful();

        // コンテナを再起動したときと同じように、2回目を実行しても重複して入らない
        $this->artisan('app:seed-if-empty')
            ->expectsOutput('シード済みのため、何もしません。')
            ->assertSuccessful();

        $this->assertSame(3, User::count());
        $this->assertSame(212, MasterRecord::count());
    }
}
