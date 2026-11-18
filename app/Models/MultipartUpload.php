<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MultipartUpload extends Model
{
    protected $fillable = ['bucket_id', 'key', 'upload_id', 'content_type', 'metadata', 'is_public'];

    protected $casts = ['metadata' => 'array', 'is_public' => 'boolean'];

    public function bucket(): BelongsTo
    {
        return $this->belongsTo(Bucket::class);
    }

    public function parts(): HasMany
    {
        return $this->hasMany(MultipartPart::class);
    }
}
