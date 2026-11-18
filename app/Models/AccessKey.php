<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccessKey extends Model
{
    protected $fillable = ['access_key', 'secret_encrypted', 'scopes', 'revoked_at'];

    protected $casts = ['scopes' => 'array', 'revoked_at' => 'datetime', 'last_used_at' => 'datetime'];
}
