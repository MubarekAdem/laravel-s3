<?php
namespace App\Console\Commands;
use App\Models\ObjectRecord; use Illuminate\Console\Command; use Illuminate\Support\Facades\File;
class PurgeDeletedObjects extends Command {protected $signature='object-store:purge';protected $description='Permanently remove expired trashed objects';public function handle():int{$objects=ObjectRecord::whereNotNull('deleted_at')->where('deleted_at','<',now()->subDays(config('object-store.trash_days')))->get();foreach($objects as $object){File::delete($object->path);$object->delete();}$this->info("Purged {$objects->count()} object(s).");return self::SUCCESS;}}
