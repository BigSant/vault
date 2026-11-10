<?php
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
Artisan::command('vault:status',function(){ $this->info('Database: '.(DB::select('SELECT 1')?'ready':'unavailable'));$this->info('Owners: '.DB::table('members')->where('role','owner')->count()); });

Artisan::command('vault:recover-user {email}',function(){
    $user=\App\Models\User::where('email',strtolower($this->argument('email')))->firstOrFail();
    $input=json_decode(stream_get_contents(STDIN),true,flags:JSON_THROW_ON_ERROR);
    if(!is_string($input['password']??null)||strlen($input['password'])<24)throw new RuntimeException('Invalid recovery input');
    DB::transaction(function()use($user,$input){
        app(\App\Vault\Secrets::class)->resetMfa($user->id,\OTPHP\TOTP::generate()->getSecret());
        DB::table('users')->where('id',$user->id)->update(['password'=>\Illuminate\Support\Facades\Hash::make($input['password']),'mfa_enabled'=>false,'totp_counter'=>-1]);
        DB::table('sessions')->where('user_id',$user->id)->delete();
        app(\App\Vault\Access::class)->audit(null,'user.recover',$user->id,'ok','operator');
    });
    $this->info('User recovered; password replaced, MFA enrollment required, sessions revoked.');
});
Artisan::command('vault:maintenance',function(){
    DB::table('operations')->whereIn('state',['pending','approved','running','requires_user_action'])->where('expires_at','<=',now())->update(['state'=>'expired','updated_at'=>now()]);
    DB::table('sessions')->where('last_activity','<',time()-8*3600)->delete();
    DB::table('cache')->where('expiration','<',time())->delete();
    DB::table('cache_locks')->where('expiration','<',time())->delete();
    $this->info('Expired operation, session and cache maintenance completed.');
});
