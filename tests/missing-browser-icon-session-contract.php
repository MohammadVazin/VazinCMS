<?php
declare(strict_types=1);
/** Candidate-only regression: absent static browser icons must not allocate
 * a session. Each subprocess includes the actual front controller; DB is
 * forced to :memory: so a regressed guard cannot contact a live database.
 */
$root=dirname(__DIR__);
$entry=$root.'/public/index.php';
$paths=['/favicon.ico','/apple-touch-icon.png','/apple-touch-icon-precomposed.png'];
$count=0;
foreach($paths as $uri){
    if(is_file($root.'/public'.$uri))throw new RuntimeException('fixture_icon_now_present_review_test');
    foreach(['GET','HEAD'] as $method){
        $php='$_SERVER["REQUEST_URI"]='.var_export($uri.'?cache=1',true).';'.
            '$_SERVER["REQUEST_METHOD"]='.var_export($method,true).';'.
            'ob_start();require '.var_export($entry,true).';$body=ob_get_clean();'.
            'echo json_encode(["status"=>http_response_code(),"bytes"=>strlen($body),'.
            '"session_active"=>session_status()===PHP_SESSION_ACTIVE,'.
            '"cms_bootstrap_loaded"=>class_exists("VazinCMS\\\\App",false)]);';
        $pipes=[];
        $process=proc_open([PHP_BINARY,'-r',$php],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,[
            'PATH'=>(string)getenv('PATH'),'DB_CONNECTION'=>'sqlite',
            'DB_DATABASE'=>':memory:','SESSION_SECURE'=>'false'
        ]);
        if(!is_resource($process))throw new RuntimeException('icon_test_process_unavailable');
        fclose($pipes[0]);$output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);
        fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
        $result=json_decode((string)$output,true);
        if($exit!==0 || !is_array($result) || $result['status']!==404 ||
           $result['bytes']!==0 || $result['session_active']!==false ||
           $result['cms_bootstrap_loaded']!==false){
            throw new RuntimeException('MISSING_ICON_SESSION_REGRESSION:'.$method.' '.$uri);
        }
        $count++;
    }
}
echo 'MISSING_BROWSER_ICON_SESSION=PASS cases='.$count.' no_session,no_CMS_bootstrap,empty_404'.PHP_EOL;
