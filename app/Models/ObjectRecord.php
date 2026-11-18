<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ObjectRecord extends Model
{
    protected $table = 'objects';

    protected $fillable = ['key', 'path', 'size', 'etag', 'content_type', 'metadata', 'is_public', 'deleted_at'];

    protected $casts = ['metadata' => 'array', 'is_public' => 'boolean', 'deleted_at' => 'datetime'];

    public function bucket()
    {
        return $this->belongsTo(Bucket::class);
    }
}
