<?php
// Run with PHP sodium. Only generated fixture bytes, no real backup material.
$root=sys_get_temp_dir().'/vault-crypto-'.bin2hex(random_bytes(8));mkdir($root,0700);
$script=dirname(__DIR__).'/scripts/crypt.php';
function cryptRun(string $mode,string $key,string $input,string $output):int {
    global $script;
    $pipes=[];$process=proc_open([PHP_BINARY,$script,$mode,$key,$input,$output],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    fclose($pipes[0]);stream_get_contents($pipes[1]);stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);return proc_close($process);
}
function check(bool $ok):void{if(!$ok)throw new RuntimeException('Backup authentication test failed');}
try{
    file_put_contents($root.'/key',random_bytes(32));file_put_contents($root.'/wrong',random_bytes(32));
    foreach([0,1,65536,200000]as $size){
        file_put_contents($root.'/input',$size?random_bytes($size):'');
        check(cryptRun('encrypt',$root.'/key',$root.'/input',$root.'/encrypted')===0);
        check(cryptRun('decrypt',$root.'/key',$root.'/encrypted',$root.'/plain')===0);
        check(hash_file('sha256',$root.'/input')===hash_file('sha256',$root.'/plain'));unlink($root.'/plain');
        $cipher=file_get_contents($root.'/encrypted');
        foreach([substr($cipher,0,-1),substr($cipher,0,-21),$cipher.'x',substr_replace($cipher,chr(ord($cipher[30])^1),30,1)]as $bad){
            file_put_contents($root.'/bad',$bad);check(cryptRun('decrypt',$root.'/key',$root.'/bad',$root.'/plain')!==0);check(!file_exists($root.'/plain'));
        }
        check(cryptRun('decrypt',$root.'/wrong',$root.'/encrypted',$root.'/plain')!==0);check(!file_exists($root.'/plain'));unlink($root.'/encrypted');
    }
    echo "PASS: encrypted round trip, empty/multichunk files, corruption, truncation, appended data and wrong keys.\n";
}finally{foreach(glob($root.'/*')as $file)unlink($file);rmdir($root);}
