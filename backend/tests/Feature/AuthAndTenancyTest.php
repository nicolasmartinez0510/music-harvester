<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Auth\LibraryPathResolver;
use App\Mail\PasswordResetMail;
use App\Mail\VerificationCodeMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AuthAndTenancyTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_verify_and_login(): void
    {
        Mail::fake();

        $this->postJson('/api/auth/register', [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'username' => 'ada',
            'email' => 'ada@example.com',
            'password' => 'password1',
            'password_confirmation' => 'password1',
            'avatar_id' => 'meme-01',
        ])->assertCreated();

        Mail::assertSent(VerificationCodeMail::class, function (VerificationCodeMail $mail) {
            $this->assertStringContainsString($mail->code, $mail->render());

            return true;
        });

        $this->postJson('/api/auth/login', [
            'email' => 'ada@example.com',
            'password' => 'password1',
        ])->assertStatus(403);

        $user = User::query()->where('email', 'ada@example.com')->firstOrFail();
        $user->forceFill(['email_verified_at' => now()])->save();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.first_name', 'Ada')
            ->assertJsonPath('data.username', 'ada');
    }

    public function test_disabling_email_verification_skips_mail_and_password_reset(): void
    {
        Mail::fake();

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/settings', ['email_verification_enabled' => false])
            ->assertOk()
            ->assertJsonPath('data.email_verification_enabled', false);

        $this->getJson('/api/auth/options')
            ->assertOk()
            ->assertJsonPath('data.email_verification_enabled', false);

        $this->postJson('/api/auth/register', [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'username' => 'ada',
            'email' => 'ada@example.com',
            'password' => 'password1',
            'password_confirmation' => 'password1',
            'avatar_id' => 'meme-01',
        ])->assertCreated()
            ->assertJsonPath('data.verification_required', false);

        Mail::assertNothingSent();

        $created = User::query()->where('email', 'ada@example.com')->first();
        $this->assertNotNull($created?->email_verified_at);

        $this->postJson('/api/auth/forgot-password', ['email' => 'ada@example.com'])
            ->assertStatus(422);
    }

    public function test_password_reset_code_expires(): void
    {
        Mail::fake();

        $user = User::factory()->create(['email' => 'ada@example.com']);

        $this->postJson('/api/auth/forgot-password', ['email' => 'ada@example.com'])
            ->assertOk();

        Mail::assertSent(PasswordResetMail::class, function (PasswordResetMail $mail) {
            $this->assertStringContainsString($mail->url, $mail->render());

            preg_match('/code=(\d{8})/', $mail->url, $matches);
            $this->assertNotEmpty($matches[1] ?? null);

            DB::table('password_reset_codes')->update([
                'expires_at' => now()->subMinute(),
            ]);

            $this->getJson('/api/auth/password-reset/validate?code='.$matches[1])
                ->assertStatus(422);

            return true;
        });

        $this->assertNotNull($user->id);
    }

    public function test_admin_approves_server_storage_and_cannot_delete_self(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create([
            'server_storage_status' => 'pending',
            'download_destination' => 'server',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/users/'.$member->id.'/approve-server-storage')
            ->assertOk()
            ->assertJsonPath('data.server_storage_status', 'approved');

        $this->actingAs($admin, 'sanctum')
            ->deleteJson('/api/admin/users/'.$admin->id)
            ->assertStatus(422);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson('/api/admin/users/'.$member->id)
            ->assertNoContent();

        $this->assertDatabaseMissing('users', ['id' => $member->id]);
    }

    public function test_library_roots_differ_for_admin_and_member(): void
    {
        $admin = User::factory()->admin()->create(['username' => 'boss']);
        $member = User::factory()->create([
            'username' => 'ada',
            'server_storage_status' => 'approved',
            'download_destination' => 'server',
        ]);
        $pending = User::factory()->create([
            'username' => 'pendinguser',
            'download_destination' => 'server',
            'server_storage_status' => 'pending',
        ]);

        $paths = app(LibraryPathResolver::class);
        $music = rtrim((string) config('music.path'), '/');

        $this->assertSame($music, $paths->outputRoot($admin, 'server'));
        $this->assertSame($music.'/ada', $paths->outputRoot($member, 'server'));
        $this->assertSame('direct', $paths->effectiveDestination($pending));
        $this->assertStringContainsString(
            'tmp-downloads/'.$pending->id,
            $paths->outputRoot($pending, 'direct', 9),
        );
    }

    public function test_downloads_are_isolated_per_user(): void
    {
        $ada = User::factory()->create();
        $bea = User::factory()->create();

        DB::table('download_jobs')->insert([
            'user_id' => $ada->id,
            'provider' => 'youtube_music',
            'url' => 'https://music.youtube.com/watch?v=aaaaaaaaaaa',
            'kind' => 'track',
            'status' => 'done',
            'progress' => 100,
            'download_destination' => 'server',
            'options_json' => json_encode(['format' => 'mp3_320']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($bea, 'sanctum')
            ->getJson('/api/downloads')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($ada, 'sanctum')
            ->getJson('/api/downloads')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_direct_artifact_downloads_the_file(): void
    {
        $user = User::factory()->create();
        $id = DB::table('download_jobs')->insertGetId([
            'user_id' => $user->id,
            'provider' => 'youtube_music',
            'url' => 'https://music.youtube.com/watch?v=bbbbbbbbbbb',
            'kind' => 'track',
            'status' => 'done',
            'progress' => 100,
            'download_destination' => 'direct',
            'destination_path' => '',
            'options_json' => json_encode(['format' => 'mp3_320']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $dir = storage_path('app/private/tmp-downloads/'.$user->id.'/'.$id);
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $file = $dir.'/song.mp3';
        file_put_contents($file, 'audio');
        DB::table('download_jobs')->where('id', $id)->update([
            'destination_path' => $file,
            'downloaded_paths' => json_encode([$file]),
        ]);

        $this->actingAs($user, 'sanctum')
            ->get('/api/downloads/'.$id.'/artifact')
            ->assertOk()
            ->assertDownload('song.mp3');

        @unlink($file);
    }
}
