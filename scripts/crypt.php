<?php
declare(strict_types=1);
// Authenticated streaming encryption: no key or plaintext on argv/stdout.
if (count($argv)!==5 || !in_array($argv[1],['encrypt','decrypt'],true)) exit(2);
[$script,$mode,$keyPath,$sourcePath,$targetPath]=$argv;
$key=file_get_contents($keyPath);
if(strlen($key)!==SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES)exit(2);
$source=fopen($sourcePath,'rb');$target=fopen($targetPath,'xb');chmod($targetPath,0600);
try {
    if($mode==='encrypt'){
        [$state,$header]=sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
        fwrite($target,"VLT1".$header);
        while(!feof($source)){
            $plain=fread($source,65536);
            $cipher=sodium_crypto_secretstream_xchacha20poly1305_push($state,$plain,'',SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE);
            fwrite($target,pack('N',strlen($cipher)).$cipher);
        }
        $final=sodium_crypto_secretstream_xchacha20poly1305_push($state,'','',SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL);
        fwrite($target,pack('N',strlen($final)).$final);
    } else {
        if(fread($source,4)!=='VLT1')throw new RuntimeException();
        $header=fread($source,SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
        $state=sodium_crypto_secretstream_xchacha20poly1305_init_pull($header,$key);$finished=false;
        while(!feof($source)){
            $size=fread($source,4);if(strlen($size)!==4)throw new RuntimeException();
            $size=unpack('N',$size)[1];if($size<17||$size>65553)throw new RuntimeException();
            $chunk=fread($source,$size);if(strlen($chunk)!==$size)throw new RuntimeException();
            $result=sodium_crypto_secretstream_xchacha20poly1305_pull($state,$chunk);
            if($result===false)throw new RuntimeException();
            [$plain,$tag]=$result;
            fwrite($target,$plain);
            if($tag===SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL){
                if(fread($source,1)!=='')throw new RuntimeException();$finished=true;break;
            }
        }
        if(!$finished)throw new RuntimeException();
    }
    fflush($target);fsync($target);
} catch(Throwable){fclose($target);unlink($targetPath);fwrite(STDERR,"Backup authentication/encryption failed.\n");exit(1);}
finally{sodium_memzero($key);fclose($source);}
