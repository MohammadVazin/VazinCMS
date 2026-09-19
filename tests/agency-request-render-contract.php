<?php
declare(strict_types=1);

$runtime = sys_get_temp_dir() . '/vazincms-agency-render-' . bin2hex(random_bytes(6));
mkdir($runtime, 0700, true);
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE=:memory:');
putenv('VAZINCMS_STORAGE_PATH=' . $runtime);
putenv('SESSION_SECURE=false');
putenv('APP_KEY=agency-render-contract-key-0123456789-abcdefghijklmnopqrstuvwxyz');
putenv('APP_URL=https://travel.example.test');

require dirname(__DIR__) . '/src/bootstrap.php';

use VazinCMS\View;

$check = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$copy = static function (string $locale): array {
    $marker = 'AGENCY-COPY-' . strtoupper($locale);
    return [
        'eyebrow' => $marker,
        'title' => $marker . ' title',
        'text' => $marker . ' text',
        'form_title' => $marker . ' form',
        'form_text' => $marker . ' form text',
        'consent' => $marker . ' consent',
        'submit' => $marker . ' submit',
        'side_title' => $marker . ' side',
        'side_items' => [$marker . ' first', $marker . ' second'],
        'success_title' => $marker . ' success',
        'success_text' => $marker . ' success text',
        'meta_title' => $marker . ' meta',
        'meta_description' => $marker . ' meta description',
    ];
};
$form = [
    'organization' => '', 'contact_name' => '', 'email' => '', 'phone' => '',
    'country' => '', 'website' => '', 'services' => [], 'notes' => '',
];

try {
    foreach (['fa', 'ru', 'en'] as $locale) {
        foreach ([false, true] as $submitted) {
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_POST = [];
            ob_start();
            View::renderPublic('agency-request', [
                'locale' => $locale,
                'agencyCopy' => $copy($locale),
                'form' => $form,
                'submitted' => $submitted,
                'error' => null,
                'serviceOptions' => ['flights' => 'Flights'],
                'meta' => [],
            ]);
            $html = (string) ob_get_clean();
            $marker = 'AGENCY-COPY-' . strtoupper($locale);
            $check(str_contains($html, $marker), 'Agency copy was overwritten by public chrome for ' . $locale . '.');
            if ($submitted) {
                $check(str_contains($html, $marker . ' success'), 'Submitted agency confirmation did not render for ' . $locale . '.');
            } else {
                $check(str_contains($html, 'name="organization"'), 'Agency form did not render for ' . $locale . '.');
                $check(str_contains($html, 'name="privacy_consent"'), 'Agency consent did not render for ' . $locale . '.');
            }
        }
    }
    echo "Agency request render contract: OK\n";
} finally {
    $_POST = [];
    @rmdir($runtime);
}
