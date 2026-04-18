<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Log;
use App\Services\EmailTemplateMailer;
use Illuminate\Auth\Notifications\ResetPassword;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Jetstream\HasProfilePhoto;
use Laravel\Jetstream\HasTeams;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles; // Added for role management

class User extends Authenticatable
{
    use HasRoles;
    use HasApiTokens;

    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory;
    use HasProfilePhoto;
    use HasTeams;
    use Notifiable;
    use TwoFactorAuthenticatable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'mfa_preference',
        'is_active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_recovery_codes',
        'two_factor_secret',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'profile_photo_url',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'dark_mode'         => 'boolean',
            'mfa_preference'    => 'string',
            'mfa_prompted_at'    => 'datetime',
            'is_active'          => 'boolean',
        ];
    }


    public function sendPasswordResetNotification($token): void
    {
        $broker = config('auth.defaults.passwords', 'users');
        $expiresInMinutes = (int) config("auth.passwords.{$broker}.expire", 60);
        $resetUrl = url(route('password.reset', [
            'token' => $token,
            'email' => $this->getEmailForPasswordReset(),
        ], false));

        $sent = app(EmailTemplateMailer::class)->sendForEvent(
            'password_reset',
            [
                'client' => [
                    'name' => $this->name ?: $this->email,
                    'email' => $this->email,
                ],
                'company' => [
                    'name' => config('app.name', 'DomainDash'),
                ],
                'auth' => [
                    'reset_link' => $resetUrl,
                    'reset_expires_at' => now()->addMinutes($expiresInMinutes)->toDateTimeString(),
                ],
            ],
            $this->email
        );

        if (! $sent) {
            Log::info('Falling back to default Laravel reset password notification.', [
                'user_id' => $this->id,
                'email' => $this->email,
            ]);

            $this->notify(new ResetPassword($token));
        }
    }

    /**
     * Client organisations this user is assigned to (many-to-many).
     */

    public function hasConfiguredMfa(): bool
    {
        return ! empty($this->two_factor_secret)
            && ! empty($this->two_factor_confirmed_at);
    }

    public function clients()
    {
        // Uses the default pivot table name "client_user" with client_id & user_id
        return $this->belongsToMany(Client::class)->withTimestamps();
    }
}
