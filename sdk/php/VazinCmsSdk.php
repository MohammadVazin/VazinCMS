<?php
declare(strict_types=1);
final class VazinCmsSdk
{
    public function __construct(private string $baseUrl,private string $token){}
    public function get(string $path,array $query=[]): array
    {
        $url=rtrim($this->baseUrl,'/').$path.($query?'?'.http_build_query($query):'');$h=curl_init($url);curl_setopt_array($h,[CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$this->token,'Accept: application/json'],CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS]);$body=(string)curl_exec($h);$status=(int)curl_getinfo($h,CURLINFO_RESPONSE_CODE);$err=curl_error($h);curl_close($h);if($err!=='')throw new RuntimeException($err);$data=json_decode($body,true);return['status'=>$status,'body'=>is_array($data)?$data:$body];
    }
    public function content(array$q=[]):array{return$this->get('/api/v3/content',$q);}
    public function forms(array$q=[]):array{return$this->get('/api/v3/forms',$q);}
    public function courses(array$q=[]):array{return$this->get('/api/v3/learn/courses',$q);}
    public function connectors():array{return$this->get('/api/v3/connectors');}
}
