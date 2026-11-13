<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Storage;

class CompanyDocument extends Model
{
    protected $fillable = ['title', 'description', 'file_path', 'original_name', 'mime_type', 'size', 'requires_acknowledgment', 'published_by'];

    protected function casts(): array
    {
        return ['requires_acknowledgment' => 'boolean', 'size' => 'integer'];
    }

    protected static function booted(): void
    {
        static::deleted(fn (CompanyDocument $d) => Storage::disk('local')->delete($d->file_path));
    }

    /** Utilisateurs ayant confirmé la lecture. */
    public function readers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'company_document_acknowledgments')->withPivot('acknowledged_at');
    }
}
