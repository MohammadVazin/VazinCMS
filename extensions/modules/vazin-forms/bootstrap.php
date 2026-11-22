<?php
declare(strict_types=1);

require_once __DIR__ . '/FormsModuleService.php';

use VazinCMS\{Access, ModuleContext, Security};
use VazinCMS\Modules\Forms\FormsModuleService;

return static function (ModuleContext $module): void {
    $service = static fn(): FormsModuleService => new FormsModuleService($module->db());
    $esc = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $module->registerBlock('form', [
        'label' => 'فرم تعاملی',
        'category' => 'forms',
        'schema' => ['form_key' => 'string:required'],
    ], static function (array $data) use ($service, $esc): string {
        $formKey = (string) ($data['form_key'] ?? '');
        $form = $service()->getForm($formKey);
        if ($form === null) {
            return '<div class="vz-form-missing">فرم انتخاب‌شده فعال نیست.</div>';
        }
        $fields = json_decode((string) ($form['fields_json'] ?? '[]'), true) ?: [];
        $html = '<form class="vz-dynamic-form" method="post" action="/api/v1/modules/vazin-forms/submit">'
            . '<input type="hidden" name="form_key" value="' . $esc((string) $form['form_key']) . '">'
            . '<div style="display:none" aria-hidden="true"><input type="text" name="_hp_website" tabindex="-1" autocomplete="off"></div>'
            . '<h3>' . $esc((string) $form['title']) . '</h3>';
        foreach ($fields as $f) {
            $req = !empty($f['required']) ? ' required' : '';
            $k = $esc((string) $f['key']);
            $lbl = $esc((string) $f['label']);
            $html .= '<label class="vz-form-field"><span>' . $lbl . '</span>';
            if (($f['type'] ?? 'text') === 'textarea') {
                $html .= '<textarea name="' . $k . '"' . $req . '></textarea>';
            } else {
                $type = in_array($f['type'] ?? 'text', ['email', 'number'], true) ? (string) $f['type'] : 'text';
                $html .= '<input type="' . $type . '" name="' . $k . '"' . $req . '>';
            }
            $html .= '</label>';
        }
        $html .= '<button type="submit">' . $esc((string) ($form['submit_label'] ?: 'ارسال')) . '</button></form>';
        return $html;
    });

    $module->api('POST', 'submit', static fn(array $input): array => $service()->submit(
        (string) ($input['form_key'] ?? ''),
        $input,
        (string) ($_SERVER['REMOTE_ADDR'] ?? '')
    ));

    $module->any('/admin/forms', static function () use ($service): void {
        Access::require('support');
        $subs = $service()->listSubmissions(50);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>فرم‌ساز و پیام‌ها</title></head><body><main><h1>صندوق پیام‌های دریافتی (' . count($subs) . ')</h1></main></body></html>';
    });
};
