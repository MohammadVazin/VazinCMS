<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/DatabaseTransaction.php';
require dirname(__DIR__).'/src/Security.php';
require dirname(__DIR__).'/src/SiteLocale.php';
require dirname(__DIR__).'/src/SiteTemplateBuilder.php';
use VazinCMS\SiteTemplateBuilder;

$count=0;
$assert=static function(bool $ok,string $label)use(&$count):void{
    if(!$ok)throw new RuntimeException('SITE_TEMPLATE_BUILDER_FAIL:'.$label);
    $count++;echo 'PASS '.$label.PHP_EOL;
};
$denied=static function(callable $fn,string $code)use($assert):void{
    try{$fn();}catch(RuntimeException $e){
        $assert($e->getMessage()===$code,'denied_'.$code);return;
    }
    throw new RuntimeException('SITE_TEMPLATE_BUILDER_ALLOW_UNEXPECTED:'.$code);
};
foreach([false,true] as $scoped){
    $prefix=$scoped?'scoped_':'legacy_';
    $db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $db->exec('CREATE TABLE cms_sites(id INTEGER PRIMARY KEY,status TEXT)');
    $db->exec("INSERT INTO cms_sites VALUES(1,'active')".($scoped?",(2,'active')":''));
    $db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,role TEXT,status TEXT)');
    $db->exec("INSERT INTO users VALUES(10,'owner','active'),(20,'editor','active'),(30,'admin','disabled'),(40,'admin','active'),(50,'owner','active')");
    $db->exec('CREATE TABLE cms_site_memberships(site_id INTEGER,user_id INTEGER,site_role TEXT)');
    $db->exec("INSERT INTO cms_site_memberships VALUES(1,10,'owner'),(1,20,'editor'),(1,30,'owner'),(1,40,'admin'),(2,50,'owner')");
    $db->exec('CREATE TABLE cms_settings('.($scoped?'site_id INTEGER NOT NULL,':'').'setting_key TEXT,setting_value TEXT,updated_at TEXT DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY('.($scoped?'site_id,':'').'setting_key))');
    $db->exec("INSERT INTO cms_settings(".($scoped?'site_id,':'')."setting_key,setting_value) VALUES(".($scoped?'1,':'')."'site_name','brand-preserved')");
    $db->exec('CREATE TABLE cms_pages(id INTEGER PRIMARY KEY,title TEXT,status TEXT)');
    $db->exec("INSERT INTO cms_pages VALUES(1,'page-preserved','published')");
    $service=new SiteTemplateBuilder($db,1);
    $initial=$service->read(10);
    $assert($initial['enabled']===false&&count($initial['templates'])===3&&$service->publicState()===null,$prefix.'three_independent_templates_unconfigured');
    $assert((int)$db->query('SELECT COUNT(*) FROM cms_settings')->fetchColumn()===1,$prefix.'read_does_not_write');
    $denied(fn()=>$service->read(20),'template_access_denied');
    $denied(fn()=>$service->read(30),'template_access_denied');
    $denied(fn()=>$service->read(999),'template_access_denied');

    $template=$initial['templates']['corporate'];
    $template['name']='شرکتی سفارشی';
    $template['header_layout']='centered';
    $template['components']['intro']=['type'=>'text','label'=>'معرفی مشترک','props'=>['text'=>'متن سربرگ و پابرگ']];
    $template['header']=['brand','intro','nav','cta'];
    $template['footer']=['intro','contact','social','copyright'];
    $saved=$service->saveTemplate(10,'corporate',$template,$initial['version'],true);
    $public=$service->publicState();
    $assert($saved['enabled']&&$public['template_key']==='corporate',$prefix.'save_and_activate_public_template');
    $assert($public['template']['header_layout']==='centered'&&$public['template']['header'][1]==='intro'&&$public['template']['footer'][0]==='intro',$prefix.'reusable_component_shared_between_header_footer');
    $assert($saved['version']!==$initial['version']&&$saved['previous']['enabled']===false,$prefix.'revision_version_and_previous_snapshot');

    $header=SiteTemplateBuilder::renderHeader($public,'<script>نام</script>',[
        ['label'=>'خانه','url'=>'/fa'],['label'=>'بد','url'=>'javascript:alert(1)'],['label'=>'بیرونی','url'=>'//evil.test']
    ],['enabled_locales'=>'["fa","en"]'],'fa');
    $footer=SiteTemplateBuilder::renderFooter($public,'نام سایت',[],[],'fa');
    $assert(substr_count($header,'متن سربرگ و پابرگ')===1&&substr_count($footer,'متن سربرگ و پابرگ')===1,$prefix.'public_component_reused_in_both_parts');
    $assert(str_contains($header,'&lt;script&gt;نام&lt;/script&gt;')&&!str_contains($header,'<script>'),$prefix.'public_brand_is_escaped');
    $assert(str_contains($header,'href="/fa"')&&!str_contains($header,'javascript:')&&!str_contains($header,'//evil.test'),$prefix.'public_navigation_uses_safe_internal_links');
    $assert(str_contains($header,'data-locale-switch')&&str_contains($header,'data-theme-toggle')&&str_contains($header,'data-menu-toggle'),$prefix.'locale_theme_and_mobile_controls_preserved');

    $raw=$db->query("SELECT setting_value FROM cms_settings WHERE setting_key='site_template_builder_v1'".($scoped?' AND site_id=1':''))
        ->fetchColumn();
    $denied(fn()=>$service->activate(10,'editorial',$initial['version']),'template_changed');
    $assert($db->query("SELECT setting_value FROM cms_settings WHERE setting_key='site_template_builder_v1'".($scoped?' AND site_id=1':''))
        ->fetchColumn()===$raw,$prefix.'stale_template_action_zero_mutation');

    $duplicated=$service->duplicate(10,'corporate','نسخهٔ فروشگاهی',$saved['version']);
    $custom=array_values(array_diff(array_keys($duplicated['templates']),['corporate','editorial','minimal']));
    $assert(count($custom)===1&&$duplicated['templates'][$custom[0]]['name']==='نسخهٔ فروشگاهی',$prefix.'duplicate_creates_independent_template');
    $customKey=$custom[0];
    $customTemplate=$duplicated['templates'][$customKey];
    $customTemplate['name']='فروشگاه مستقل';
    $customTemplate['footer_layout']='stacked';
    $changed=$service->saveTemplate(40,$customKey,$customTemplate,$duplicated['version']);
    $assert($changed['templates']['corporate']['name']==='شرکتی سفارشی'&&$changed['templates'][$customKey]['footer_layout']==='stacked',$prefix.'template_edits_are_independent');
    $activated=$service->activate(10,$customKey,$changed['version']);
    $assert($service->publicState()['template_key']===$customKey,$prefix.'custom_template_activation');

    $bad=$customTemplate;$bad['components']['cta']['props']['href']='javascript:alert(1)';
    $denied(fn()=>$service->saveTemplate(10,$customKey,$bad,$activated['version']),'template_link_invalid');
    foreach(['//evil.test','/%2fevil.test','/%255cevil.test','/%0d%0a','/path/../bad'] as $url){
        $unsafe=$customTemplate;$unsafe['components']['cta']['props']['href']=$url;
        $denied(fn()=>$service->saveTemplate(10,$customKey,$unsafe,$activated['version']),'template_link_invalid');
    }
    $rootLink=$customTemplate;$rootLink['components']['cta']['props']['href']='/';
    $assert(SiteTemplateBuilder::validateTemplate($rootLink)['components']['cta']['props']['href']==='/',$prefix.'root_link_allowed');
    $badBrand=$customTemplate;$badBrand['components']['brand']['props']['tagline']=['bad'];
    $denied(fn()=>SiteTemplateBuilder::validateTemplate($badBrand),'template_brand_invalid');
    $badPart=$customTemplate;$badPart['header'][]='intro';
    $denied(fn()=>SiteTemplateBuilder::validateTemplate($badPart),'template_part_reference_invalid');
    $badCode=$customTemplate;$badCode['components']['intro']['type']='php';
    $denied(fn()=>SiteTemplateBuilder::validateTemplate($badCode),'template_component_invalid');
    $bad=$customTemplate;$bad['components']['social']['props']['links']=[['label'=>'x','url'=>'http://example.test']];
    $denied(fn()=>$service->saveTemplate(10,$customKey,$bad,$activated['version']),'template_social_link_invalid');
    $bad=$customTemplate;$bad['components']['intro']['props']['text']='<script>alert(1)</script>';
    $safe=$service->saveTemplate(10,$customKey,$bad,$activated['version']);
    $rendered=SiteTemplateBuilder::renderHeader($service->publicState(),'سایت',[],[],'fa');
    $assert(str_contains($rendered,'&lt;script&gt;alert(1)&lt;/script&gt;')&&!str_contains($rendered,'<script>alert'),$prefix.'component_text_is_plain_escaped_data');
    $assert($db->query('SELECT title||status FROM cms_pages WHERE id=1')->fetchColumn()==='page-preservedpublished',$prefix.'template_operations_do_not_mutate_pages');
    $assert($db->query("SELECT setting_value FROM cms_settings WHERE setting_key='site_name'")->fetchColumn()==='brand-preserved',$prefix.'other_settings_preserved');

    $restored=$service->restore(10,$safe['version']);
    $assert($service->publicState()['template_key']===$customKey&&$service->publicState()['template']['name']==='فروشگاه مستقل',$prefix.'restore_previous_template_collection');
    $again=$service->restore(10,$restored['version']);
    $assert($service->publicState()['template']['name']==='فروشگاه مستقل',$prefix.'template_restore_is_reversible');
    $reset=$service->resetTemplate(10,$customKey,$again['version']);
    $assert($reset['templates'][$customKey]['header']===SiteTemplateBuilder::defaults()['corporate']['header'],$prefix.'custom_template_reset_to_safe_base');
    $deleted=$service->deleteCustom(10,$customKey,$reset['version']);
    $assert(!isset($deleted['templates'][$customKey])&&$deleted['active_template']==='corporate',$prefix.'custom_template_delete_falls_back_to_builtin');
    $denied(fn()=>$service->deleteCustom(10,'corporate',$deleted['version']),'template_builtin_protected');
    $denied(fn()=>$service->saveTemplate(10,'missing',$template,$deleted['version']),'template_unavailable');
    if($scoped){
        $second=new SiteTemplateBuilder($db,2);$b=$second->read(50);
        $assert($second->publicState()===null,$prefix.'tenant_b_does_not_inherit_template_a');
        $denied(fn()=>$service->read(50),'template_access_denied');
        $secondSaved=$second->saveTemplate(50,'editorial',$b['templates']['editorial'],$b['version'],true);
        $assert($second->publicState()['template_key']==='editorial'&&$service->publicState()['template_key']==='corporate',$prefix.'tenant_templates_are_independent');
    }
}
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec("CREATE TABLE cms_sites(id INTEGER PRIMARY KEY,status TEXT);INSERT INTO cms_sites VALUES(1,'active'),(2,'active');CREATE TABLE cms_settings(setting_key TEXT,setting_value TEXT)");
$denied(fn()=>new SiteTemplateBuilder($db,1),'template_schema_unavailable');
foreach(SiteTemplateBuilder::defaults() as $key=>$template){
    $validated=SiteTemplateBuilder::validateTemplate($template);
    $assert($validated===$template,'preset_'.$key.'_valid');
}
echo 'SITE_TEMPLATE_BUILDER_CONTRACT=PASS checks='.$count.' independent_templates,reusable_components,header_footer_composition,version_guard,tenant_isolation,escaped_public_render'.PHP_EOL;
