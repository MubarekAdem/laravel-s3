<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MultipartPart extends Model
{
    protected $fillable = ['part_number', 'path', 'size', 'etag'];

    public function upload(): BelongsTo
    {
        return $this->belongsTo(MultipartUpload::class, 'multipart_upload_id');
    }
}
