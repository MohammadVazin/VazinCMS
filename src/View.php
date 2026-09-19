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
            try{foreach(Database::connection()->query('SELECT setting_key,setting_value FROM cms_settings')->fetchAll() as $row)$settings[$row['setting_key']]=$row['setting_value'];}catch(\Throwable){}
            $data+=['settings'=>$settings,'profile'=>$settings['site_profile']??'corporate','siteName'=>$settings['site_name']??'VazinCMS'];
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
