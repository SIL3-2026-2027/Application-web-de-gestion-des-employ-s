<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    public const ROLE_ADMIN = 'super-admin';
    public const ROLE_RH = 'rh';
    public const ROLE_MANAGER = 'manager';
    public const ROLE_EMPLOYE = 'employe';
    public const ROLE_DIRECTION = 'direction';

    public const ROLES = [
        self::ROLE_ADMIN,
        self::ROLE_RH,
        self::ROLE_MANAGER,
        self::ROLE_EMPLOYE,
        self::ROLE_DIRECTION,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'is_active',
        'notification_preferences',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'notification_preferences' => 'array',
        ];
    }

    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }

    /** Adresse de la photo que l'utilisateur a choisie lui-même (null sinon). Elle change à chaque nouvelle photo. */
    public function ownAvatarUrl(): ?string
    {
        return $this->avatar_path ? "/api/v1/users/{$this->id}/avatar?v=".substr(md5($this->avatar_path), 0, 8) : null;
    }

    /** Photo affichée : celle de l'utilisateur, à défaut celle que les RH ont mise sur son dossier. */
    public function avatarUrl(): ?string
    {
        return $this->ownAvatarUrl() ?? $this->employee?->photoUrl();
    }

    /** NOT-05 : e-mail activé par défaut, sauf si l'utilisateur l'a désactivé pour cette catégorie. */
    public function wantsEmailFor(string $category): bool
    {
        return (bool) ($this->notification_preferences[$category] ?? true);
    }

    /** Comptes actifs des RH et administrateurs, destinataires des notifications RH. */
    public static function hrRecipients(): \Illuminate\Database\Eloquent\Collection
    {
        return static::role([self::ROLE_RH, self::ROLE_ADMIN])->where('is_active', true)->get();
    }

    /** Accès complet aux dossiers du personnel. */
    public function isHr(): bool
    {
        return $this->hasAnyRole([self::ROLE_ADMIN, self::ROLE_RH]);
    }

    /** Lecture de tous les dossiers (RH, admin, direction). */
    public function canViewAllEmployees(): bool
    {
        return $this->hasAnyRole([self::ROLE_ADMIN, self::ROLE_RH, self::ROLE_DIRECTION]);
    }
}
