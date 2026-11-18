<?php namespace App\Models; use Illuminate\Database\Eloquent\Model; class AuditEvent extends Model {protected $guarded=[];protected $casts=['context'=>'array'];}
