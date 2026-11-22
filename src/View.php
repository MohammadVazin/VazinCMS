<?php
declare(strict_types=1);
namespace VazinCMS;

final class View
{
    public static function render(string $name, array $data = []): void
    {
        try {
            $currentUser=Auth::user();
            $brandDefault=is_array($currentUser)?self::configuredDefaultLocale():null;
            UiLocale::boot(null,is_array($currentUser)?(string)($currentUser['identity_locale']??''):null,$brandDefault);
            $customerBrand=SiteBrand::name();$openAdvisoryCount=$currentUser?ContentAdvisoryService::openCount():0;extract($data, EXTR_SKIP);
            $root = dirname(__DIR__);$appVersion = Version::current();
            $scope=get_defined_vars();
            $html=LocalizedTemplate::render([$root.'/views/partials/head.php',$root.'/views/'.$name.'.php',$root.'/views/partials/foot.php'],$scope);
            echo UiLocale::html($html);
        } catch (\Throwable $e) { throw $e; }
    }

    public static function renderPublic(string $name, array $data = []): void
    {
        try {
            UiLocale::boot((string)($data['locale']??''));$settings=[];
            try{
                $viewDb=Database::connection();
                if(SiteScopeMode::active($viewDb)){$settings=(new SiteScopedContentRepository($viewDb,SiteContext::id()))->settingsMap();}
                else{foreach($viewDb->query('SELECT setting_key,setting_value FROM cms_settings')->fetchAll() as $row)$settings[$row['setting_key']]=$row['setting_value'];}
            }catch(\Throwable){}
            $data+=['settings'=>$settings,'profile'=>$settings['site_profile']??'corporate','siteName'=>$settings['site_name']??'VazinCMS'];
            $siteDesign=null;
            try{$siteDesign=(new SiteDesign(Database::connection(),SiteContext::id()))->publicState();}
            catch(\Throwable){/* Invalid or unavailable design never emits unsafe CSS. */}
            $siteTemplate=null;
            try{$siteTemplate=(new SiteTemplateBuilder(Database::connection(),SiteContext::id()))->publicState();}
            catch(\Throwable){/* Invalid template state never injects custom markup. */}
            $canvasHtml=null;$canvasHeaderHtml=null;$canvasFooterHtml=null;
            try{
                $canvasService=new SiteCanvas(Database::connection(),SiteContext::id());
                if(is_array($siteTemplate)){
                    $canvasKey=(string)$siteTemplate['template_key'];
                    $headerLayout=$canvasService->publicLayout($canvasKey,'header');
                    $footerLayout=$canvasService->publicLayout($canvasKey,'footer');
                    if($headerLayout!==null)$canvasHeaderHtml=SiteCanvasRenderer::renderShell($headerLayout,'header',$data);
                    if($footerLayout!==null)$canvasFooterHtml=SiteCanvasRenderer::renderShell($footerLayout,'footer',$data);
                }
                if(($name==='cms-page'&&!empty($data['page']))||$name==='cms-archive'){
                    $canvasKind=$name==='cms-page'?'page':'archive';
                    $canvasKey=$siteTemplate['template_key']??'corporate';
                    $pageId=$canvasKind==='page'?(int)($data['page']['id']??0):null;
                    $canvasState=$canvasService->publicLayout($canvasKey,$canvasKind,$pageId);
                    if($canvasState!==null){$canvasHtml=SiteCanvasRenderer::render($canvasState,$data);$name='canvas';}
                }
            }catch(\Throwable){/* fail closed to the existing safe theme and page views */}
            $viewName=$name;extract($data,EXTR_SKIP);$root=dirname(__DIR__);$appVersion=Version::current();
            $scope=get_defined_vars();
            $templates=ThemeManager::templates($viewName);
            $html=LocalizedTemplate::render($templates['paths'],$scope,$templates['allowed_roots']);
            echo UiLocale::html($html);
        } catch (\Throwable $e) { throw $e; }
    }

    public static function renderStandalone(string $name, array $data = []): void
    {
        UiLocale::boot((string)($data['locale']??'fa'));
        $root=dirname(__DIR__);$appVersion=Version::current();extract($data,EXTR_SKIP);
        $scope=get_defined_vars();
        $html=LocalizedTemplate::render([$root.'/views/'.$name.'.php'],$scope,[$root.'/views']);
        echo UiLocale::html($html);
    }

    /** This is deliberately used only for an authenticated admin render.
     * Error, recovery and anonymous routes retain the no-database fallback. */
    private static function configuredDefaultLocale():?string
    {
        try{
            $query=Database::connection()->prepare("SELECT setting_value FROM cms_settings WHERE setting_key=:key LIMIT 1");
            $query->execute(['key'=>'default_locale']);
            $value=strtolower(trim((string)($query->fetchColumn()?:'')));
            return$value!==''?$value:null;
        }catch(\Throwable){return null;}
    }
}
