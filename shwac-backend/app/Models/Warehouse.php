<?php

namespace App\Models;

use App\Models\Concerns\BranchScoped;
use Illuminate\Database\Eloquent\Model;

class Warehouse extends Model
{
    protected $guarded = ['id'];

    use BranchScoped;

    protected $casts = ['is_active' => 'boolean'];

    public function branch() { return $this->belongsTo(Branch::class); }
    public function owner() { return $this->belongsTo(User::class, 'user_id'); }
    public function stockLevels() { return $this->hasMany(StockLevel::class); }

    public function isVan(): bool { return $this->type === 'van'; }
}
