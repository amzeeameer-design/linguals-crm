<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @param string $token */
    public function sendPasswordResetNotification($token): void
    {
        \App\Services\PortalSettings::apply();
        $template = \App\Services\PortalSettings::templates()['password_reset'];
        $values = ['{{name}}' => $this->name, '{{link}}' => route('password.reset', ['token' => $token, 'email' => $this->email]), '{{reference}}' => '', '{{note}}' => ''];
        \Illuminate\Support\Facades\Mail::to($this->email, $this->name)->send(new \App\Mail\TalentStageMail(strtr($template['subject'], $values), strtr($template['body'], $values)));
    }

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
