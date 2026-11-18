<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Bucket extends Model
{
    protected $fillable = ['name', 'quota_bytes'];

    public function objects()
    {
        return $this->hasMany(ObjectRecord::class, 'bucket_id');
    }
}
