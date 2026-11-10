<?php
namespace App\Http;
use App\Vault\Operations;
use Illuminate\Http\Request;
final class AgentApi
{
    public function __construct(private Operations $ops){}
    public function catalog(Request $r){return ['entries'=>$this->ops->catalog($r->attributes->get('machine'))];}
    public function execute(Request $r){
        $d=$r->validate(['entry_id'=>'required|uuid','action'=>'required|string|max:100','request_id'=>'required|string|max:80','arguments'=>'prohibited']);
        return $this->ops->execute($r->attributes->get('machine'),$d['entry_id'],$d['action'],$d['request_id']);
    }
    public function status(Request $r,string $id){return $this->ops->status($r->attributes->get('machine'),$id);}
    public function cancel(Request $r,string $id){return $this->ops->cancel($r->attributes->get('machine'),$id);}
    public function mcp(Request $r){
        try { return $this->message($r); }
        catch(\Throwable $error){
            $id=$r->json('id');if(!is_string($id)&&!is_int($id))$id=null;
            $status=$error instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface?$error->getStatusCode():503;
            $code=in_array($status,[400,415,422])?-32602:-32000;
            return response()->json(['jsonrpc'=>'2.0','id'=>$id,'error'=>['code'=>$code,'message'=>$code===-32602?'Invalid parameters':'Action unavailable or refused']]);
        }
    }
    private function message(Request $r){
        abort_unless($r->isJson(),415);$message=$r->json()->all();
        abort_unless(($message['jsonrpc']??null)==='2.0'&&is_string($message['method']??null),400);
        if(!array_key_exists('id',$message))return response('',202);
        $id=$message['id'];abort_unless(is_string($id)||is_int($id)||$id===null,400);
        $schemas=[
            'vault_search'=>['description'=>'List authorized aliases and actions. Never returns credentials.','properties'=>[],'required'=>[]],
            'vault_execute'=>['description'=>'Execute a registered action; requires a unique request_id for idempotency.','properties'=>['entry_id'=>['type'=>'string'],'action'=>['type'=>'string'],'request_id'=>['type'=>'string']],'required'=>['entry_id','action','request_id']],
            'vault_status'=>['description'=>'Read your operation status.','properties'=>['id'=>['type'=>'string']],'required'=>['id']],
            'vault_cancel'=>['description'=>'Cancel your operation.','properties'=>['id'=>['type'=>'string']],'required'=>['id']],
        ];
        $method=$message['method'];
        if($method==='initialize')$result=['protocolVersion'=>'2025-03-26','capabilities'=>['tools'=>(object)[]],'serverInfo'=>['name'=>'vault','version'=>'1.0.0']];
        elseif($method==='ping')$result=(object)[];
        elseif($method==='tools/list'){$result=['tools'=>[]];foreach($schemas as $name=>$s)$result['tools'][]=['name'=>$name,'description'=>$s['description'],'inputSchema'=>['type'=>'object','properties'=>(object)$s['properties'],'required'=>$s['required'],'additionalProperties'=>false]];}
        elseif($method==='tools/call'){
            $name=$message['params']['name']??'';$args=$message['params']['arguments']??[];abort_unless(is_string($name)&&isset($schemas[$name])&&is_array($args),422);
            abort_unless(!array_diff(array_keys($args),array_keys($schemas[$name]['properties'])),422);
            foreach($schemas[$name]['required'] as $key)abort_unless(isset($args[$key])&&is_string($args[$key])&&strlen($args[$key])<=100,422);
            foreach(['id','entry_id']as $key)if(isset($args[$key]))abort_unless(\Illuminate\Support\Str::isUuid($args[$key]),422);
            $m=$r->attributes->get('machine');
            $data=match($name){'vault_search'=>['entries'=>$this->ops->catalog($m)],'vault_execute'=>$this->ops->execute($m,$args['entry_id'],$args['action'],$args['request_id']),
                'vault_status'=>$this->ops->status($m,$args['id']),'vault_cancel'=>$this->ops->cancel($m,$args['id'])};
            $result=['content'=>[['type'=>'text','text'=>json_encode($data,JSON_THROW_ON_ERROR)]],'isError'=>false];
        }else return response()->json(['jsonrpc'=>'2.0','id'=>$id,'error'=>['code'=>-32601,'message'=>'Method not found']]);
        return response()->json(['jsonrpc'=>'2.0','id'=>$id,'result'=>$result]);
    }
}
