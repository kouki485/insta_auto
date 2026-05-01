<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Account;
use App\Models\PostSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PostsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_upload_image_stores_file_under_account_directory(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $account = $this->makeAccount();

        $file = UploadedFile::fake()->image('feed.jpg', width: 1080, height: 1080);

        $response = $this->postJson('/api/posts/upload-image', ['image' => $file])
            ->assertStatus(201);

        $path = $response->json('data.image_path');
        $this->assertNotEmpty($path);
        $this->assertStringStartsWith("images/{$account->id}/", $path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_upload_image_rejects_non_image_extensions(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->makeAccount();
        $file = UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf');

        $this->postJson('/api/posts/upload-image', ['image' => $file])
            ->assertStatus(422);
    }

    public function test_upload_image_rejects_too_small_dimensions(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->makeAccount();
        $file = UploadedFile::fake()->image('tiny.jpg', width: 100, height: 100);

        $this->postJson('/api/posts/upload-image', ['image' => $file])
            ->assertStatus(422);
    }

    public function test_store_creates_scheduled_post_when_image_exists(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $account = $this->makeAccount();
        $file = UploadedFile::fake()->image('story.jpg', 1080, 1920);
        $path = $this->postJson('/api/posts/upload-image', ['image' => $file])
            ->json('data.image_path');

        $response = $this->postJson('/api/posts', [
            'type' => 'story',
            'image_path' => $path,
            'caption' => null,
            'scheduled_at' => now()->addDay()->toIso8601String(),
        ])->assertStatus(201);

        $this->assertSame('story', $response->json('data.type'));
        $this->assertDatabaseHas('post_schedules', [
            'account_id' => $account->id,
            'type' => 'story',
            'image_path' => $path,
            'status' => PostSchedule::STATUS_SCHEDULED,
        ]);
    }

    public function test_store_rejects_when_image_path_does_not_exist(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->makeAccount();

        $this->postJson('/api/posts', [
            'type' => 'feed',
            'image_path' => 'images/missing.jpg',
            'scheduled_at' => now()->addDay()->toIso8601String(),
        ])->assertStatus(422);
    }

    public function test_store_rejects_past_scheduled_at(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->makeAccount();
        $file = UploadedFile::fake()->image('past.jpg', 1080, 1080);
        $path = $this->postJson('/api/posts/upload-image', ['image' => $file])
            ->json('data.image_path');

        $this->postJson('/api/posts', [
            'type' => 'feed',
            'image_path' => $path,
            'scheduled_at' => now()->subHour()->toIso8601String(),
        ])->assertStatus(422);
    }

    public function test_destroy_blocks_when_status_is_not_scheduled(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $account = $this->makeAccount();
        $post = PostSchedule::query()->create([
            'account_id' => $account->id,
            'type' => 'feed',
            'image_path' => 'images/test.jpg',
            'caption' => 'x',
            'scheduled_at' => now()->addHour(),
            'status' => PostSchedule::STATUS_POSTING,
        ]);

        $this->deleteJson("/api/posts/{$post->id}")->assertStatus(422);
        $this->assertDatabaseHas('post_schedules', ['id' => $post->id]);
    }

    public function test_destroy_succeeds_for_scheduled_post(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $account = $this->makeAccount();
        $post = PostSchedule::query()->create([
            'account_id' => $account->id,
            'type' => 'feed',
            'image_path' => 'images/test.jpg',
            'scheduled_at' => now()->addHour(),
            'status' => PostSchedule::STATUS_SCHEDULED,
        ]);

        $this->deleteJson("/api/posts/{$post->id}")->assertOk();
        $this->assertDatabaseMissing('post_schedules', ['id' => $post->id]);
    }

    public function test_destroy_other_account_post_returns_404(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->makeAccount();          // 「現在」のアカウント (primary)
        $other = $this->makeAccount(); // 別アカウント
        $foreign = PostSchedule::query()->create([
            'account_id' => $other->id,
            'type' => 'feed',
            'image_path' => 'images/foreign.jpg',
            'scheduled_at' => now()->addHour(),
            'status' => PostSchedule::STATUS_SCHEDULED,
        ]);

        $this->deleteJson("/api/posts/{$foreign->id}")->assertStatus(404);
        $this->assertDatabaseHas('post_schedules', ['id' => $foreign->id]);
    }

    private function makeAccount(): Account
    {
        return Account::query()->create([
            'store_name' => 'うなら',
            'ig_username' => 'unara_post_'.uniqid(),
            'ig_session_path' => '/storage/sessions/1.json',
            'proxy_url' => 'http://u:p@example.com',
            'ig_password' => 'secret',
            'daily_dm_limit' => 5,
            'daily_follow_limit' => 5,
            'daily_like_limit' => 30,
            'status' => Account::STATUS_ACTIVE,
            'timezone' => 'Asia/Tokyo',
        ]);
    }
}
