<?php

namespace Tests\Feature;

use App\Http\Controllers\SpaController;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class SpaTest extends TestCase
{
    private const HTML = '<!doctype html><div id="root"></div>';

    protected function tearDown(): void
    {
        File::deleteDirectory(dirname(SpaController::indexPath()));

        parent::tearDown();
    }

    private function placeBuiltSpa(): void
    {
        File::ensureDirectoryExists(dirname(SpaController::indexPath()));
        File::put(SpaController::indexPath(), self::HTML);
    }

    public function test_returns_spa_index_for_any_screen_path(): void
    {
        $this->placeBuiltSpa();

        foreach (['/', '/login', '/changesets/1/edit'] as $path) {
            $response = $this->get($path);

            $response->assertOk();
            $this->assertSame(self::HTML, $response->streamedContent());
            $this->assertStringContainsString('no-cache', $response->headers->get('Cache-Control'));
        }
    }

    public function test_unknown_api_path_returns_json_404_instead_of_spa(): void
    {
        $this->placeBuiltSpa();

        $this->getJson('/api/unknown')
            ->assertNotFound()
            ->assertJsonStructure(['message']);
    }

    public function test_returns_404_when_spa_is_not_built(): void
    {
        // 開発環境では画面は Vite が配信するため、Laravel 側には index.html がない
        $this->get('/login')->assertNotFound();
    }
}
