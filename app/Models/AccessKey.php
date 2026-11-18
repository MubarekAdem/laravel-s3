<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccessKey extends Model
{
    protected $fillable = ['access_key', 'secret_encrypted', 'scopes', 'bucket_names', 'revoked_at'];

    protected $hidden = ['secret_encrypted'];

    protected $casts = ['scopes' => 'array', 'bucket_names' => 'array', 'revoked_at' => 'datetime', 'last_used_at' => 'datetime'];
}
