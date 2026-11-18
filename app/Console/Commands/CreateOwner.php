<?php
namespace App\Console\Commands;
use App\Models\User; use Illuminate\Console\Command; use Illuminate\Support\Facades\Hash;
class CreateOwner extends Command {protected $signature='object-store:owner {email} {--name=Owner}';protected $description='Create or reset the local storage owner';public function handle():int{$password=$this->secret('Password');User::updateOrCreate(['email'=>$this->argument('email')],['name'=>$this->option('name'),'password'=>Hash::make($password)]);$this->info('Owner saved.');return self::SUCCESS;}}
