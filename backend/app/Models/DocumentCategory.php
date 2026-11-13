<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentCategory extends Model
{
    protected $fillable = ['name', 'code', 'visible_to_employee', 'visible_to_manager', 'has_expiry'];

    protected function casts(): array
    {
        return [
            'visible_to_employee' => 'boolean',
            'visible_to_manager' => 'boolean',
            'has_expiry' => 'boolean',
        ];
    }

    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class);
    }
}
