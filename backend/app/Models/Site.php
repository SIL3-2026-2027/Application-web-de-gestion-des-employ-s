<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Site extends Model
{
    protected $fillable = ['name', 'address', 'city', 'country'];

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }
}
