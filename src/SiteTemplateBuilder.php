<?php
declare(strict_types=1);

namespace VazinCMS;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Safe, site-owned template composition.
 *
 * This builder edits only declarative component references and approved
 * component properties. It never accepts HTML, PHP, JavaScript or raw CSS.
 * PHP theme packages and extension activation remain separate operations.
 */
final class SiteTemplateBuilder
{
    private const KEY='site_template_builder_v1';
    private const BUILT_INS=['corporate','editorial','minimal'];
    private bool $scoped;

    public function __construct(private readonly PDO $pdo,private readonly int $siteId)
    {
        if($siteId<1)throw new RuntimeException('template_site_unavailable');
        $driver=(string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if($driver==='sqlite'){
            $columns=$pdo->query('PRAGMA table_info(cms_settings)')->fetchAll(PDO::FETCH_ASSOC);
            if(!$columns)throw new RuntimeException('template_schema_unavailable');
            $this->scoped=in_array('site_id',array_column($columns,'name'),true);
        }elseif($driver==='pgsql'){
            $q=$pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema=current_schema() AND table_name='cms_settings'");
            $columns=$q->fetchAll(PDO::FETCH_COLUMN);
            if(!$columns)throw new RuntimeException('template_schema_unavailable');
            $this->scoped=in_array('site_id',$columns,true);
        }else throw new RuntimeException('template_driver_unsupported');
        $this->assertSite(false);
    }

    public static function defaults():array
    {
        $shared=[
            'brand'=>['type'=>'brand','label'=>'نام و نشان سایت','props'=>['tagline'=>'']],
            'nav'=>['type'=>'nav','label'=>'فهرست اصلی','props'=>[]],
            'cta'=>['type'=>'button','label'=>'دکمهٔ تماس','props'=>['text'=>'تماس با ما','href'=>'/fa/page/contact']],
            'intro'=>['type'=>'text','label'=>'معرفی کوتاه','props'=>['text'=>'به وب‌سایت ما خوش آمدید.']],
            'contact'=>['type'=>'contact','label'=>'اطلاعات تماس','props'=>['phone'=>'','email'=>'','address'=>'']],
            'social'=>['type'=>'social','label'=>'شبکه‌های اجتماعی','props'=>['links'=>[]]],
            'copyright'=>['type'=>'copyright','label'=>'حق نشر','props'=>[]],
        ];
        return [
            'corporate'=>[
                'name'=>'شرکتی',
                'header_layout'=>'inline','footer_layout'=>'columns',
                'components'=>$shared,
                'header'=>['brand','nav','cta'],
                'footer'=>['brand','intro','contact','social','copyright'],
            ],
            'editorial'=>[
                'name'=>'نشریه',
                'header_layout'=>'centered','footer_layout'=>'columns',
                'components'=>[
                    'brand'=>['type'=>'brand','label'=>'نام نشریه','props'=>['tagline'=>'روایت، تحلیل و دیدگاه']],
                    'nav'=>['type'=>'nav','label'=>'فهرست بخش‌ها','props'=>[]],
                    'intro'=>['type'=>'text','label'=>'دربارهٔ نشریه','props'=>['text'=>'داستان‌ها و نوشته‌های تازه را دنبال کنید.']],
                    'social'=>['type'=>'social','label'=>'شبکه‌های اجتماعی','props'=>['links'=>[]]],
                    'copyright'=>['type'=>'copyright','label'=>'حق نشر','props'=>[]],
                ],
                'header'=>['brand','nav'],
                'footer'=>['intro','nav','social','copyright'],
            ],
            'minimal'=>[
                'name'=>'مینیمال',
                'header_layout'=>'stacked','footer_layout'=>'stacked',
                'components'=>[
                    'brand'=>['type'=>'brand','label'=>'نشان ساده','props'=>['tagline'=>'']],
                    'nav'=>['type'=>'nav','label'=>'پیوندهای اصلی','props'=>[]],
                    'copyright'=>['type'=>'copyright','label'=>'حق نشر','props'=>[]],
                ],
                'header'=>['brand','nav'],
                'footer'=>['copyright'],
            ],
        ];
    }

    private function assertSite(bool $lock):void
    {
        if(!$this->scoped &&
            (int)$this->pdo->query('SELECT COUNT(*) FROM cms_sites')->fetchColumn()!==1){
            throw new RuntimeException('template_schema_unavailable');
        }
        $sql="SELECT id FROM cms_sites WHERE id=:id AND status IN('active','staging')";
        if($lock&&(string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='pgsql')$sql.=' FOR UPDATE';
        $q=$this->pdo->prepare($sql);$q->execute(['id'=>$this->siteId]);
        try{$ok=$q->fetchColumn()!==false;}finally{$q->closeCursor();}
        if(!$ok)throw new RuntimeException('template_site_unavailable');
    }

    private function authorize(int $actorId):void
    {
        if($actorId<1)throw new RuntimeException('template_access_denied');
        $this->assertSite(false);
        if($this->scoped){
            $q=$this->pdo->prepare("SELECT m.site_role FROM cms_site_memberships m JOIN users u ON u.id=m.user_id WHERE m.site_id=:site AND m.user_id=:actor AND u.status='active'");
            $q->execute(['site'=>$this->siteId,'actor'=>$actorId]);
        }else{
            $q=$this->pdo->prepare("SELECT role FROM users WHERE id=:actor AND status='active'");
            $q->execute(['actor'=>$actorId]);
        }
        try{$role=$q->fetchColumn();}finally{$q->closeCursor();}
        if(!in_array($role,['owner','admin'],true))throw new RuntimeException('template_access_denied');
    }

    private function raw():?string
    {
        $sql='SELECT setting_value FROM cms_settings WHERE setting_key=:key';
        $params=['key'=>self::KEY];
        if($this->scoped){$sql.=' AND site_id=:site';$params['site']=$this->siteId;}
        $q=$this->pdo->prepare($sql);$q->execute($params);
        try{$raw=$q->fetchColumn();}finally{$q->closeCursor();}
        return $raw===false?null:(string)$raw;
    }

    private static function internalHref(mixed $value):string
    {
        if(!is_string($value)||strlen($value)>240||preg_match('~\A/(?!/)[A-Za-z0-9/_?&=.%#-]{0,239}\z~D',$value)!==1
            ||str_contains($value,'..')||str_contains($value,'//')){
            throw new RuntimeException('template_link_invalid');
        }
        $decoded=$value;
        for($i=0;$i<4;$i++){
            $next=rawurldecode($decoded);
            if(str_starts_with($next,'//')||str_contains($next,'..')||
               str_contains($next,chr(92))||preg_match('/[\x00-\x20\x7f]/',$next)){
                throw new RuntimeException('template_link_invalid');
            }
            if($next===$decoded)break;
            $decoded=$next;
        }
        if(preg_match('/%[a-fA-F0-9]{2}/',$decoded))throw new RuntimeException('template_link_invalid');
        return $value;
    }

    private static function externalHref(mixed $value):string
    {
        if(!is_string($value)||strlen($value)>500||filter_var($value,FILTER_VALIDATE_URL)===false){
            throw new RuntimeException('template_social_link_invalid');
        }
        $parts=parse_url($value);
        if(!is_array($parts)||strtolower((string)($parts['scheme']??''))!=='https'||
            empty($parts['host'])||isset($parts['user'])||isset($parts['pass'])){
            throw new RuntimeException('template_social_link_invalid');
        }
        return $value;
    }

    public static function validateTemplate(array $input):array
    {
        $keys=array_keys($input);$expected=['name','header_layout','footer_layout','components','header','footer'];
        sort($keys);sort($expected);
        if($keys!==$expected)throw new RuntimeException('template_fields_invalid');
        if(!is_string($input['name'])||!is_string($input['header_layout'])||!is_string($input['footer_layout']))throw new RuntimeException('template_fields_invalid');
        $name=trim($input['name']);
        if(mb_strlen($name)<2||mb_strlen($name)>60)throw new RuntimeException('template_name_invalid');
        $headerLayout=$input['header_layout'];
        $footerLayout=$input['footer_layout'];
        if(!in_array($headerLayout,['inline','centered','stacked'],true)||
            !in_array($footerLayout,['columns','stacked'],true)){
            throw new RuntimeException('template_layout_invalid');
        }
        if(!is_array($input['components'])||!$input['components']||count($input['components'])>16){
            throw new RuntimeException('template_component_limit');
        }
        $components=[];
        foreach($input['components'] as $id=>$component){
            if(!is_string($id))throw new RuntimeException('template_component_invalid');
            if(preg_match('/\A(?:[a-z][a-z0-9-]{1,31}|c-[a-f0-9]{8})\z/D',$id)!==1||
                !is_array($component))throw new RuntimeException('template_component_invalid');
            $ck=array_keys($component);sort($ck);$ce=['label','props','type'];sort($ce);
            if($ck!==$ce||!is_string($component['type'])||!is_string($component['label'])||
                !is_array($component['props']))throw new RuntimeException('template_component_invalid');
            $type=$component['type'];$label=trim($component['label']);$props=$component['props'];
            if(!in_array($type,['brand','nav','button','text','contact','social','copyright'],true)||
                mb_strlen($label)<1||mb_strlen($label)>50)throw new RuntimeException('template_component_invalid');
            $cleanProps=match($type){
                'brand'=>self::validateBrand($props),
                'nav','copyright'=>self::propsExact($props,[]),
                'button'=>self::validateButton($props),
                'text'=>self::validateText($props),
                'contact'=>self::validateContact($props),
                'social'=>self::validateSocial($props),
                default=>throw new RuntimeException('template_component_invalid')
            };
            $components[$id]=['type'=>$type,'label'=>$label,'props'=>$cleanProps];
        }
        $header=self::validateOrder($input['header'],$components);
        $footer=self::validateOrder($input['footer'],$components);
        return ['name'=>$name,'header_layout'=>$headerLayout,'footer_layout'=>$footerLayout,
            'components'=>$components,'header'=>$header,'footer'=>$footer];
    }

    private static function propsExact(array $props,array $expected):array
    {
        $keys=array_keys($props);sort($keys);sort($expected);
        if($keys!==$expected)throw new RuntimeException('template_component_props_invalid');
        return $props;
    }
    private static function stringValue(mixed $value,int $max,string $error,bool $empty=true):string
    {
        if(!is_string($value)||mb_strlen($value)>$max||(!$empty&&trim($value)===''))throw new RuntimeException($error);
        return trim($value);
    }
    private static function validateBrand(array $props):array
    {
        self::propsExact($props,['tagline']);
        return ['tagline'=>self::stringValue($props['tagline'],120,'template_brand_invalid')];
    }
    private static function validateButton(array $props):array
    {
        self::propsExact($props,['text','href']);
        $text=self::stringValue($props['text'],60,'template_button_invalid',false);
        return ['text'=>$text,'href'=>self::internalHref($props['href'])];
    }
    private static function validateText(array $props):array
    {
        self::propsExact($props,['text']);
        $text=self::stringValue($props['text'],300,'template_text_invalid',false);
        return ['text'=>$text];
    }
    private static function validateContact(array $props):array
    {
        self::propsExact($props,['phone','email','address']);
        $phone=self::stringValue($props['phone'],40,'template_contact_invalid');
        $email=self::stringValue($props['email'],120,'template_contact_invalid');
        $address=self::stringValue($props['address'],160,'template_contact_invalid');
        if(preg_match('/\A[0-9+() .-]*\z/D',$phone)!==1||
            ($email!==''&&filter_var($email,FILTER_VALIDATE_EMAIL)===false))throw new RuntimeException('template_contact_invalid');
        return ['phone'=>$phone,'email'=>$email,'address'=>$address];
    }
    private static function validateSocial(array $props):array
    {
        self::propsExact($props,['links']);
        if(!is_array($props['links'])||!array_is_list($props['links'])||count($props['links'])>4){
            throw new RuntimeException('template_social_invalid');
        }
        $links=[];
        foreach($props['links'] as $link){
            if(!is_array($link))throw new RuntimeException('template_social_invalid');
            self::propsExact($link,['label','url']);
            $label=self::stringValue($link['label'],30,'template_social_invalid',false);
            $links[]=['label'=>$label,'url'=>self::externalHref($link['url'])];
        }
        return ['links'=>$links];
    }
    private static function validateOrder(mixed $order,array $components):array
    {
        if(!is_array($order)||!array_is_list($order)||count($order)>8){
            throw new RuntimeException('template_part_invalid');
        }
        $seen=[];$clean=[];
        foreach($order as $id){
            if(!is_string($id)||!isset($components[$id])||isset($seen[$id])){
                throw new RuntimeException('template_part_reference_invalid');
            }
            $seen[$id]=true;$clean[]=$id;
        }
        return $clean;
    }

    private static function defaultState():array
    {
        return ['enabled'=>false,'active_template'=>'corporate','templates'=>self::defaults(),
            'previous'=>null,'revision'=>null,'version'=>hash('sha256','template-builder-not-configured')];
    }
    private static function validateSnapshot(mixed $snapshot):array
    {
        if(!is_array($snapshot)||!is_bool($snapshot['enabled']??null)||
            !is_string($snapshot['active_template']??null)||!is_array($snapshot['templates']??null)){
            throw new RuntimeException('template_stored_data_invalid');
        }
        $templates=self::validateTemplates($snapshot['templates']);
        if(!isset($templates[$snapshot['active_template']])){
            throw new RuntimeException('template_stored_data_invalid');
        }
        return ['enabled'=>$snapshot['enabled'],'active_template'=>$snapshot['active_template'],'templates'=>$templates];
    }
    private static function validateTemplates(array $templates):array
    {
        if(count($templates)<3||count($templates)>8)throw new RuntimeException('template_collection_invalid');
        foreach(self::BUILT_INS as $key)if(!isset($templates[$key]))throw new RuntimeException('template_builtin_missing');
        $clean=[];
        foreach($templates as $key=>$template){
            $key=(string)$key;
            if(preg_match('/\A(?:corporate|editorial|minimal|custom-[a-f0-9]{8})\z/D',$key)!==1||
                !is_array($template))throw new RuntimeException('template_collection_invalid');
            $clean[$key]=self::validateTemplate($template);
        }
        return $clean;
    }
    private static function decode(?string $raw):array
    {
        if($raw===null)return self::defaultState();
        try{$value=json_decode($raw,true,32,JSON_THROW_ON_ERROR);}catch(Throwable){throw new RuntimeException('template_stored_data_invalid');}
        if(!is_array($value)||($value['schema']??null)!==1||!is_bool($value['enabled']??null)||
            !is_string($value['active_template']??null)||!is_array($value['templates']??null)||
            !is_string($value['revision']??null)||preg_match('/\A[a-f0-9]{32}\z/D',$value['revision'])!==1){
            throw new RuntimeException('template_stored_data_invalid');
        }
        $templates=self::validateTemplates($value['templates']);
        if(!isset($templates[$value['active_template']]))throw new RuntimeException('template_stored_data_invalid');
        $previous=isset($value['previous'])&&$value['previous']!==null?self::validateSnapshot($value['previous']):null;
        return ['enabled'=>$value['enabled'],'active_template'=>$value['active_template'],
            'templates'=>$templates,'previous'=>$previous,'revision'=>$value['revision'],
            'version'=>hash('sha256',$raw)];
    }

    public function read(int $actorId):array
    {
        $this->authorize($actorId);
        return self::decode($this->raw());
    }
    public function publicState():?array
    {
        $state=self::decode($this->raw());
        if(!$state['enabled'])return null;
        $key=$state['active_template'];
        return ['template_key'=>$key,'template'=>$state['templates'][$key]];
    }

    private function snapshot(array $state):array
    {
        return ['enabled'=>$state['enabled'],'active_template'=>$state['active_template'],
            'templates'=>$state['templates']];
    }
    private function persist(array $state,array $previous):array
    {
        $record=['schema'=>1,'enabled'=>$state['enabled'],'active_template'=>$state['active_template'],
            'revision'=>bin2hex(random_bytes(16)),'templates'=>$state['templates'],'previous'=>$previous];
        $json=json_encode($record,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $params=['key'=>self::KEY,'value'=>$json];
        if($this->scoped){
            $params['site']=$this->siteId;
            $sql='INSERT INTO cms_settings(site_id,setting_key,setting_value) VALUES(:site,:key,:value) ON CONFLICT(site_id,setting_key) DO UPDATE SET setting_value=excluded.setting_value,updated_at=CURRENT_TIMESTAMP';
        }else{
            $sql='INSERT INTO cms_settings(setting_key,setting_value) VALUES(:key,:value) ON CONFLICT(setting_key) DO UPDATE SET setting_value=excluded.setting_value,updated_at=CURRENT_TIMESTAMP';
        }
        $this->pdo->prepare($sql)->execute($params);
        return self::decode($json);
    }
    private function checkVersion(array $before,string $expected):void
    {
        if(preg_match('/\A[a-f0-9]{64}\z/D',$expected)!==1||
            !hash_equals($before['version'],$expected))throw new RuntimeException('template_changed');
    }

    public function saveTemplate(int $actorId,string $key,array $input,string $expected,bool $activate=false):array
    {
        $template=self::validateTemplate($input);
        $this->authorize($actorId);
        return DatabaseTransaction::immediate($this->pdo,function()use($actorId,$key,$template,$expected,$activate):array{
            $this->assertSite(true);$this->authorize($actorId);
            $before=self::decode($this->raw());$this->checkVersion($before,$expected);
            if(!isset($before['templates'][$key]))throw new RuntimeException('template_unavailable');
            $previous=$this->snapshot($before);
            $before['templates'][$key]=$template;
            if($activate){$before['active_template']=$key;$before['enabled']=true;}
            return $this->persist($before,$previous);
        });
    }
    public function activate(int $actorId,string $key,string $expected):array
    {
        $this->authorize($actorId);
        return DatabaseTransaction::immediate($this->pdo,function()use($actorId,$key,$expected):array{
            $this->assertSite(true);$this->authorize($actorId);
            $before=self::decode($this->raw());$this->checkVersion($before,$expected);
            if(!isset($before['templates'][$key]))throw new RuntimeException('template_unavailable');
            $previous=$this->snapshot($before);$before['active_template']=$key;$before['enabled']=true;
            return $this->persist($before,$previous);
        });
    }
    public function duplicate(int $actorId,string $sourceKey,string $name,string $expected):array
    {
        if(!is_string($name))throw new RuntimeException('template_name_invalid');
        $name=trim($name);
        if(mb_strlen($name)<2||mb_strlen($name)>60)throw new RuntimeException('template_name_invalid');
        $this->authorize($actorId);
        return DatabaseTransaction::immediate($this->pdo,function()use($actorId,$sourceKey,$name,$expected):array{
            $this->assertSite(true);$this->authorize($actorId);
            $before=self::decode($this->raw());$this->checkVersion($before,$expected);
            if(!isset($before['templates'][$sourceKey])||count($before['templates'])>=8){
                throw new RuntimeException('template_duplicate_limit');
            }
            $previous=$this->snapshot($before);$key='custom-'.bin2hex(random_bytes(4));
            $copy=$before['templates'][$sourceKey];$copy['name']=$name;
            $before['templates'][$key]=self::validateTemplate($copy);
            return $this->persist($before,$previous);
        });
    }
    public function restore(int $actorId,string $expected):array
    {
        $this->authorize($actorId);
        return DatabaseTransaction::immediate($this->pdo,function()use($actorId,$expected):array{
            $this->assertSite(true);$this->authorize($actorId);
            $before=self::decode($this->raw());$this->checkVersion($before,$expected);
            if(!is_array($before['previous']))throw new RuntimeException('template_previous_unavailable');
            $previous=$this->snapshot($before);$target=$before['previous'];
            $target['previous']=null;
            return $this->persist($target,$previous);
        });
    }
    public function resetTemplate(int $actorId,string $key,string $expected):array
    {
        $this->authorize($actorId);
        return DatabaseTransaction::immediate($this->pdo,function()use($actorId,$key,$expected):array{
            $this->assertSite(true);$this->authorize($actorId);
            $before=self::decode($this->raw());$this->checkVersion($before,$expected);
            if(!isset($before['templates'][$key]))throw new RuntimeException('template_unavailable');
            $previous=$this->snapshot($before);$defaults=self::defaults();
            $reset=$defaults[$key]??$defaults['corporate'];
            if(!isset($defaults[$key]))$reset['name']=$before['templates'][$key]['name'];
            $before['templates'][$key]=$reset;
            return $this->persist($before,$previous);
        });
    }
    public function deleteCustom(int $actorId,string $key,string $expected):array
    {
        if(!str_starts_with($key,'custom-'))throw new RuntimeException('template_builtin_protected');
        $this->authorize($actorId);
        return DatabaseTransaction::immediate($this->pdo,function()use($actorId,$key,$expected):array{
            $this->assertSite(true);$this->authorize($actorId);
            $before=self::decode($this->raw());$this->checkVersion($before,$expected);
            if(!isset($before['templates'][$key]))throw new RuntimeException('template_unavailable');
            $previous=$this->snapshot($before);unset($before['templates'][$key]);
            if($before['active_template']===$key){$before['active_template']='corporate';$before['enabled']=true;}
            return $this->persist($before,$previous);
        });
    }

    private static function escape(string $value):string{return Security::e($value);}
    private static function safeMenuHref(mixed $value):?string
    {
        try{return self::internalHref($value);}catch(RuntimeException){return null;}
    }
    private static function componentHtml(array $component,string $siteName,array $menu,array $settings,string $locale):string
    {
        $p=$component['props'];$label=self::escape($component['label']);
        return match($component['type']){
            'brand'=>'<a class="travel-brand vz-template-brand" href="/'.self::escape($locale).'"><strong>'.self::escape($siteName).'</strong>'.(($p['tagline']??'')!==''?'<small>'.self::escape($p['tagline']).'</small>':'').'</a>',
            'nav'=>self::renderNav($menu),
            'button'=>'<a class="vz-template-button" href="'.self::escape($p['href']).'">'.self::escape($p['text']).'</a>',
            'text'=>'<p class="vz-template-text" aria-label="'.$label.'">'.self::escape($p['text']).'</p>',
            'contact'=>self::renderContact($p),
            'social'=>self::renderSocial($p),
            'copyright'=>'<small class="vz-template-copyright">© '.date('Y').' '.self::escape($siteName).'</small>',
            default=>''
        };
    }
    private static function renderNav(array $menu):string
    {
        $items='';
        foreach($menu as $item){
            $href=self::safeMenuHref($item['url']??null);
            $label=trim((string)($item['label']??''));
            if($href===null||$label==='')continue;
            $items.='<a href="'.self::escape($href).'">'.self::escape($label).'</a>';
        }
        return $items===''?'':'<nav class="vz-template-nav" data-site-menu aria-label="فهرست سایت">'.$items.'</nav>';
    }
    private static function renderTools(array $settings,string $locale):string
    {
        $enabled=json_decode((string)($settings['enabled_locales']??'[]'),true);
        if(!is_array($enabled)||!$enabled)$enabled=['fa','ru','en','ar'];
        $enabled=array_values(array_filter($enabled,static fn($code)=>is_string($code)&&isset(SiteLocale::NAMES[$code])));
        if(!$enabled)$enabled=['fa','ru','en','ar'];
        $copy=SiteLocale::text($locale);
        $options='';
        foreach($enabled as $code){
            $options.='<option value="'.self::escape($code).'"'.($code===$locale?' selected':'').'>'.self::escape(SiteLocale::NAMES[$code]).'</option>';
        }
        return '<button class="mobile-menu" type="button" data-menu-toggle aria-label="'.self::escape($copy['menu']).'">☰</button>'.
            '<div class="header-tools vz-template-tools"><label class="language"><span>'.self::escape($copy['language']).'</span><select aria-label="'.self::escape($copy['language']).'" data-locale-switch>'.$options.'</select></label>'.
            '<button class="theme-toggle" type="button" data-theme-toggle aria-label="'.self::escape($copy['theme']).'">◐</button></div>';
    }
    private static function renderContact(array $p):string
    {
        $items='';
        if(($p['phone']??'')!=='')$items.='<a href="tel:'.self::escape(preg_replace('/[^0-9+]/','',$p['phone'])??'').'">'.self::escape($p['phone']).'</a>';
        if(($p['email']??'')!=='')$items.='<a href="mailto:'.self::escape($p['email']).'">'.self::escape($p['email']).'</a>';
        if(($p['address']??'')!=='')$items.='<span>'.self::escape($p['address']).'</span>';
        return $items===''?'':'<address class="vz-template-contact">'.$items.'</address>';
    }
    private static function renderSocial(array $p):string
    {
        $items='';
        foreach($p['links']??[] as $link){
            $items.='<a href="'.self::escape($link['url']).'" target="_blank" rel="noopener noreferrer">'.self::escape($link['label']).'</a>';
        }
        return $items===''?'':'<div class="vz-template-social" aria-label="شبکه‌های اجتماعی">'.$items.'</div>';
    }
    private static function renderPart(array $state,string $part,string $siteName,array $menu,array $settings,string $locale):string
    {
        $template=$state['template'];$layout=$part==='header'?$template['header_layout']:$template['footer_layout'];
        $ids=$template[$part];$items='';
        foreach($ids as $id){
            if(isset($template['components'][$id])){
                $items.='<div class="vz-template-component vz-template-component--'.self::escape($template['components'][$id]['type']).'">'.self::componentHtml($template['components'][$id],$siteName,$menu,$settings,$locale).'</div>';
            }
        }
        $class=$part==='header'?'vz-template-header vz-template-header--'.$layout:'vz-template-footer vz-template-footer--'.$layout;
        $templateKey=self::escape((string)$state['template_key']);
        if($part==='header')return '<header class="travel-header '.$class.'" data-template-key="'.$templateKey.'">'.$items.self::renderTools($settings,$locale).'</header>';
        return '<footer class="travel-footer '.$class.'" data-template-key="'.$templateKey.'">'.$items.'</footer>';
    }
    public static function renderHeader(?array $state,string $siteName,array $menu,array $settings,string $locale):?string
    {
        return $state===null?null:self::renderPart($state,'header',$siteName,$menu,$settings,$locale);
    }
    public static function renderFooter(?array $state,string $siteName,array $menu,array $settings,string $locale):?string
    {
        return $state===null?null:self::renderPart($state,'footer',$siteName,$menu,$settings,$locale);
    }
}
