<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bucket extends Model
{
    protected $fillable = ['name', 'quota_bytes'];

    public function objects(): HasMany
    {
        return $this->hasMany(ObjectRecord::class, 'bucket_id');
    }

    public function multipartUploads(): HasMany
    {
        return $this->hasMany(MultipartUpload::class);
    }
}
