<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public const TYPE_STAFF = 'staff';
    public const TYPE_TECHNICIAN = 'technician';
    public const TYPE_CUSTOMER = 'customer';
    public const TYPE_VENDOR = 'vendor';

    protected $guarded = ['id'];

    protected $hidden = ['password', 'remember_token'];

    /** @var array<string>|null */
    private ?array $permissionCache = null;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'is_active' => 'boolean',
            'password' => 'hashed',
            'last_lat' => 'float',
            'last_lng' => 'float',
            'last_seen_at' => 'datetime',
            'location_consent_at' => 'datetime',
        ];
    }

    public function branch() { return $this->belongsTo(Branch::class); }
    public function roles() { return $this->belongsToMany(Role::class); }
    public function customer() { return $this->hasOne(Customer::class); }
    public function assignedJobs() { return $this->hasMany(ServiceJob::class, 'technician_id'); }
    public function van() { return $this->hasOne(Warehouse::class)->where('type', 'van'); }
    public function attendances() { return $this->hasMany(Attendance::class); }

    public function hasRole(string ...$slugs): bool
    {
        return $this->roles->whereIn('slug', $slugs)->isNotEmpty();
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole('super_admin');
    }

    /** @return array<string> */
    public function permissionSlugs(): array
    {
        return $this->permissionCache ??= $this->roles()
            ->with('permissions:id,slug')
            ->get()
            ->flatMap(fn (Role $r) => $r->permissions->pluck('slug'))
            ->unique()->values()->all();
    }

    public function hasPermission(string $slug): bool
    {
        return $this->isSuperAdmin() || in_array($slug, $this->permissionSlugs(), true);
    }

    public function canSeeAllBranches(): bool
    {
        return $this->hasPermission('branches.all');
    }

    public function assignRole(string ...$slugs): static
    {
        $ids = Role::whereIn('slug', $slugs)->pluck('id');
        $this->roles()->syncWithoutDetaching($ids);
        $this->unsetRelation('roles');
        $this->permissionCache = null;

        return $this;
    }

    public function isTechnician(): bool { return $this->user_type === self::TYPE_TECHNICIAN; }
    public function isCustomer(): bool { return $this->user_type === self::TYPE_CUSTOMER; }
    public function isStaff(): bool { return in_array($this->user_type, [self::TYPE_STAFF, self::TYPE_TECHNICIAN], true); }
}
