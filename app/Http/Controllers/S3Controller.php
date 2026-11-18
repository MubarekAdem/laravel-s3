<?php
namespace App\Http\Controllers;
use App\Models\{AuditEvent,Bucket,ObjectRecord}; use Illuminate\Http\Request; use Illuminate\Support\Facades\File; use Illuminate\Support\Str;
class S3Controller extends Controller {
 private function xml(array $data,int $status=200){return response()->view('s3.xml',$data,$status,['Content-Type'=>'application/xml']);}
 private function bucket(string $name){return Bucket::where('name',$name)->firstOrFail();}
 private function path(Bucket $b,string $key){return config('object-store.root').DIRECTORY_SEPARATOR.$b->id.DIRECTORY_SEPARATOR.hash('sha256',$key);}
 private function audit(Request $r,string $action,?string $bucket=null,?string $key=null):void{AuditEvent::create(['request_id'=>$r->header('x-amz-request-id'),'actor'=>optional($r->attributes->get('s3_key'))->access_key,'action'=>$action,'bucket'=>$bucket,'object_key'=>$key,'ip'=>$r->ip(),'context'=>['method'=>$r->method()]]);}
 public function buckets(){return $this->xml(['buckets'=>Bucket::orderBy('name')->get()]);}
 public function createBucket(Request $r,string $bucket){Bucket::firstOrCreate(['name'=>$bucket]);$this->audit($r,'bucket.create',$bucket); return response('',200);}
 public function list(Request $r,string $bucket){$b=$this->bucket($bucket);return $this->xml(['bucket'=>$b,'objects'=>$b->objects()->whereNull('deleted_at')->orderBy('key')->get()]);}
 public function put(Request $r,string $bucket,string $key){$b=$this->bucket($bucket); if($r->getContentLength()>config('object-store.max_bytes')) abort(413,'Object exceeds configured size.');$p=$this->path($b,$key);File::ensureDirectoryExists(dirname($p));$body=$r->getContent();File::put($p,$body);$o=$b->objects()->updateOrCreate(['key'=>$key],['path'=>$p,'size'=>strlen($body),'etag'=>md5($body),'content_type'=>$r->header('Content-Type'),'metadata'=>collect($r->headers->all())->filter(fn($v,$k)=>str_starts_with($k,'x-amz-meta-'))->map(fn($v)=>$v[0])->all(),'is_public'=>$r->header('x-amz-acl')==='public-read','deleted_at'=>null]);$this->audit($r,'object.put',$bucket,$key);return response('',200,['ETag'=>'"'.$o->etag.'"']);}
 public function get(string $bucket,string $key){$o=$this->bucket($bucket)->objects()->where('key',$key)->whereNull('deleted_at')->firstOrFail();if(!File::exists($o->path))abort(404);return response()->file($o->path,['Content-Type'=>$o->content_type ?: 'application/octet-stream','ETag'=>'"'.$o->etag.'"']);}
 public function delete(Request $r,string $bucket,string $key){$o=$this->bucket($bucket)->objects()->where('key',$key)->whereNull('deleted_at')->firstOrFail();$o->update(['deleted_at'=>now()]);$this->audit($r,'object.delete',$bucket,$key);return response('',204);}
}
