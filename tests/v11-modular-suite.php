<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$tmpDir = sys_get_temp_dir() . '/vazincms-v11-suite-' . bin2hex(random_bytes(6));
mkdir($tmpDir, 0700, true);
$dbFile = $tmpDir . '/v11.sqlite';

putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE=' . $dbFile);
putenv('VAZINCMS_STORAGE_PATH=' . $tmpDir);
putenv('SESSION_SECURE=false');
putenv('PAGE_CACHE_ENABLED=true');
putenv('EXTERNAL_DELIVERY_ENABLED=true');
putenv('APP_KEY=' . bin2hex(random_bytes(32)));

require $root . '/src/bootstrap.php';

use VazinCMS\{
    BlockEditor,
    BlockRegistry,
    BlueprintManager,
    CapabilityGuard,
    Database,
    DynamicContentService,
    ExtensionManager,
    ExtensionManifest,
    ExtensionRuntime,
    HookCollection,
    ModuleDatabase,
    PageCacheService,
    PaymentGatewayRegistry,
    Version
};
use VazinCMS\Contracts\PaymentGatewayInterface;

$assert = static function (bool $cond, string $msg): void {
    if (!$cond) {
        throw new RuntimeException('V11_SUITE_FAIL: ' . $msg);
    }
};

$cleanup = static function (string $dir) use (&$cleanup): void {
    if (!is_dir($dir)) return;
    foreach (new FilesystemIterator($dir, FilesystemIterator::SKIP_DOTS) as $item) {
        if ($item->isDir() && !$item->isLink()) $cleanup($item->getPathname());
        else @unlink($item->getPathname());
    }
    @rmdir($dir);
};

try {
    // 1. Version & Pure Micro-Core Verification
    $assert(Version::current() === '11.1.0', 'Expected Version 11.1.0, got ' . Version::current());
    $assert(!is_file($root . '/src/TravelVisaCommerceService.php'), 'Vertical TravelVisa class still in src/');
    $assert(!is_file($root . '/src/VisaApplicationService.php'), 'Vertical Visa class still in src/');
    $assert(!is_dir($root . '/extensions/modules/vazin-ai-site-assistant'), 'Proprietary AI module still in public repo');

    // Boot full schema + migrations via automated-install
    putenv('CMS_OWNER_NAME=Vazin Owner');
    putenv('CMS_OWNER_EMAIL=owner@vazincms.test');
    putenv('CMS_OWNER_PASSWORD=SuperSecurePass2026!');
    ob_start();
    require $root . '/scripts/automated-install.php';
    ob_end_clean();

    $pdo = Database::connection();
    ExtensionManager::reset();
    ExtensionManager::synchronize();
    ExtensionRuntime::ensureBooted();

    // 2. Module System v2 Discovery & Active Open-Core Modules
    $activeKeys = array_column(ExtensionManager::activeModules(), 'extension_key');
    foreach (['vazin-commerce', 'vazin-lms', 'vazin-booking', 'vazin-forms', 'seo', 'content-migration'] as $expectedMod) {
        $assert(in_array($expectedMod, $activeKeys, true), "Expected module '{$expectedMod}' to be active");
    }

    // 3. CapabilityGuard & SSRF Defense
    $commerceManifest = ExtensionManifest::fromDirectory($root . '/extensions/modules/vazin-commerce');
    $assert($commerceManifest->schema() === 2, 'Commerce manifest should be schema v2');
    $assert(CapabilityGuard::has($commerceManifest, 'db:isolated'), 'Commerce should have db:isolated');
    $assert(!CapabilityGuard::has($commerceManifest, 'http:outbound'), 'Commerce should not have http:outbound');
    $assert(CapabilityGuard::isTenantSafe($commerceManifest), 'Commerce should pass tenant safety check');

    $deniedHttp = false;
    try {
        CapabilityGuard::assertOutboundUrl($commerceManifest, 'https://127.0.0.1/secret');
    } catch (Throwable) {
        $deniedHttp = true;
    }
    $assert($deniedHttp, 'Undeclared outbound HTTP / SSRF must be blocked');

    // 4. ModuleDatabase Prefixing, SQLi Prevention & Multi-Tenant Isolation
    $dbSite1 = new ModuleDatabase($pdo, 'vazin-commerce', 1);
    $dbSite2 = new ModuleDatabase($pdo, 'vazin-commerce', 2);
    $assert($dbSite1->tableName('products') === 'mod_vazin_commerce_products', 'Unexpected module table prefix');

    $sqliBlocked = false;
    try {
        $dbSite1->tableName('users; DROP TABLE users');
    } catch (InvalidArgumentException) {
        $sqliBlocked = true;
    }
    $assert($sqliBlocked, 'Invalid module table identifier was not rejected');

    $dbSite1->ensureTable('test_items', ['title' => 'string:120']);
    $id1 = $dbSite1->insert('test_items', ['title' => 'Tenant 1 Secret Item']);
    $id2 = $dbSite2->insert('test_items', ['title' => 'Tenant 2 Secret Item']);
    $assert($dbSite1->count('test_items') === 1, 'Site 1 should only see 1 item');
    $assert($dbSite2->count('test_items') === 1, 'Site 2 should only see 1 item');
    $assert($dbSite2->find('test_items', $id1) === null, 'Site 2 must NEVER read Site 1 row by ID');
    $assert(!$dbSite2->delete('test_items', $id1), 'Site 2 must NEVER delete Site 1 row');

    // 5. HookCollection Priority + Fault Isolation (Zero White-Screen-of-Death)
    $hooks = new HookCollection();
    $order = [];
    $hooks->on('test.run', 'mod-late', static function () use (&$order): void { $order[] = 'late'; }, 20);
    $hooks->on('test.run', 'mod-broken', static function (): void { throw new RuntimeException('Simulated plugin crash'); }, 10);
    $hooks->on('test.run', 'mod-early', static function () use (&$order): void { $order[] = 'early'; }, 5);
    $emitRes = $hooks->emit('test.run');
    $assert($order === ['early', 'late'], 'Priority execution or crash survival failed');
    $assert(count($emitRes) === 3 && $emitRes[1]['ok'] === false && $emitRes[2]['ok'] === true, 'Hook fault isolation result mismatch');

    $hooks->addFilter('price.calc', 'mod-discount', static fn(float $p): float => $p * 0.9, 10);
    $hooks->addFilter('price.calc', 'mod-bad-type', static fn(float $p): string => 'corrupted-string', 20);
    $hooks->addFilter('price.calc', 'mod-crash', static fn(float $p): float => throw new RuntimeException('Filter crash'), 30);
    $filteredPrice = $hooks->applyFilters('price.calc', 100.0);
    $assert(abs($filteredPrice - 90.0) < 0.001, 'Filter fault & type isolation failed');

    // 6. E-Commerce Module + Pluggable PaymentGatewayInterface + Visual Block
    PaymentGatewayRegistry::register('test-pay', new class implements PaymentGatewayInterface {
        public function key(): string { return 'mock-gateway'; }
        public function label(): string { return 'Mock Instant Gateway'; }
        public function supportsCurrency(string $currency): bool { return $currency === 'USD'; }
        public function createCheckout(array $order, array $options = []): array {
            return ['status' => 'paid', 'provider_ref' => 'MOCK-' . $order['order_number'], 'checkout_url' => 'https://pay.example.com/checkout', 'instructions' => null];
        }
        public function verifyCallback(array $payload, array $headers = []): array {
            return ['verified' => true, 'order_number' => $payload['order_number'] ?? null, 'provider_ref' => 'MOCK', 'status' => 'paid'];
        }
    });

    $commerce = new \VazinCMS\Modules\Commerce\CommerceService(new ModuleDatabase($pdo, 'vazin-commerce', 1));
    $prod = $commerce->createProduct([
        'sku' => 'PRO-CMS-01',
        'title' => 'لایسنس سازمانی',
        'summary' => 'پکیج حرفه‌ای',
        'price' => 200,
        'currency' => 'USD',
        'stock' => 3,
    ]);
    $commerce->createCoupon(['code' => 'LAUNCH25', 'discount_type' => 'percent', 'discount_value' => 25, 'min_order' => 100]);
    $checkout = $commerce->checkout(
        [['product_id' => (int) $prod['id'], 'qty' => 2]],
        ['name' => 'Sara Ahmadi', 'email' => 'sara@example.com', 'coupon_code' => 'LAUNCH25', 'gateway' => 'mock-gateway']
    );
    $assert($checkout['subtotal'] === 400.0 && $checkout['discount'] === 100.0 && $checkout['total'] === 300.0, 'Commerce coupon/total math failed');
    $assert($checkout['payment']['status'] === 'paid', 'Custom payment gateway integration failed');
    $updatedProd = (new ModuleDatabase($pdo, 'vazin-commerce', 1))->find('products', (int) $prod['id']);
    $assert((int) ($updatedProd['stock'] ?? -1) === 1, 'Inventory stock deduction failed');

    // 7. LMS Module End-to-End (Courses, Lessons, Enrollment, Progress & Certificate)
    $lms = new \VazinCMS\Modules\Lms\LmsService(new ModuleDatabase($pdo, 'vazin-lms', 1));
    $course = $lms->createCourse(['title' => 'معماری سیستم‌های ماژولار PHP', 'instructor' => 'مهندس وزین', 'duration_hours' => 12]);
    $l1 = $lms->addLesson((int) $course['id'], ['title' => 'فصل اول: میکروکرنل']);
    $l2 = $lms->addLesson((int) $course['id'], ['title' => 'فصل دوم: ایزولاسیون امنیتی']);
    $enr = $lms->enroll((int) $course['id'], 'Ali Rezaei', 'ali@example.com');
    $p1 = $lms->completeLesson((int) $enr['id'], (int) $l1['id']);
    $assert((int) $p1['progress_percent'] === 50 && $p1['certificate_code'] === '', 'LMS 50% progress check failed');
    $p2 = $lms->completeLesson((int) $enr['id'], (int) $l2['id']);
    $assert((int) $p2['progress_percent'] === 100 && str_starts_with((string) $p2['certificate_code'], 'CERT-'), 'LMS certificate issuance failed');

    // 8. Booking Module Capacity Protection
    $booking = new \VazinCMS\Modules\Booking\BookingService(new ModuleDatabase($pdo, 'vazin-booking', 1));
    $srv = $booking->createService(['title' => 'مشاوره فنی اختصاصی', 'duration_minutes' => 45, 'capacity_per_slot' => 1]);
    $res1 = $booking->reserve(['service_id' => (int) $srv['id'], 'customer_name' => 'Mina', 'customer_email' => 'mina@example.com', 'slot_date' => '2026-12-01', 'slot_time' => '10:00']);
    $assert(str_starts_with((string) $res1['booking_code'], 'BK-'), 'Booking code generation failed');
    $doubleBooked = false;
    try {
        $booking->reserve(['service_id' => (int) $srv['id'], 'customer_name' => 'User2', 'customer_email' => 'u2@example.com', 'slot_date' => '2026-12-01', 'slot_time' => '10:00']);
    } catch (RuntimeException) {
        $doubleBooked = true;
    }
    $assert($doubleBooked, 'Booking slot over-capacity was not prevented');

    // 9. Forms Module + Anti-Spam Honeypot
    $forms = new \VazinCMS\Modules\Forms\FormsModuleService(new ModuleDatabase($pdo, 'vazin-forms', 1));
    $forms->saveForm('contact-us', 'فرم ارتباط با ما', [
        ['key' => 'full_name', 'label' => 'نام', 'type' => 'text', 'required' => true],
        ['key' => 'email', 'label' => 'ایمیل', 'type' => 'email', 'required' => true],
    ]);
    $sub = $forms->submit('contact-us', ['full_name' => 'Reza', 'email' => 'reza@example.com'], '198.51.100.7');
    $assert($sub['submission_id'] > 0, 'Form submission failed');
    $spamCaught = false;
    try {
        $forms->submit('contact-us', ['full_name' => 'Bot', 'email' => 'bot@example.com', '_hp_website' => 'https://spam.example']);
    } catch (InvalidArgumentException) {
        $spamCaught = true;
    }
    $assert($spamCaught, 'Honeypot spam protection did not trigger');

    // 10. Visual Block Editor (Native v11 Blocks + Module Blocks + XSS Sanitization)
    BlockRegistry::register('vazin-forms', 'xss-probe', ['label' => 'XSS Probe'], static fn(): string => '<div onclick="alert(1)">SafeText<script>alert(2)</script></div>');
    $doc = BlockEditor::decode(json_encode([
        'schema' => 1,
        'blocks' => [
            ['type' => 'hero', 'data' => ['badge' => 'v11', 'title' => 'سریع‌تر از وردپرس', 'subtitle' => 'تست هسته', 'cta_label' => 'شروع', 'cta_url' => '/shop']],
            ['type' => 'vazin-commerce/product-grid', 'data' => ['limit' => 4, 'heading' => 'فروشگاه']],
            ['type' => 'vazin-lms/course-grid', 'data' => ['limit' => 4, 'heading' => 'دوره‌ها']],
            ['type' => 'vazin-forms/form', 'data' => ['form_key' => 'contact-us']],
            ['type' => 'vazin-forms/xss-probe', 'data' => []],
        ],
    ], JSON_THROW_ON_ERROR));
    $rendered = BlockEditor::render($doc);
    $assert(str_contains($rendered, 'لایسنس سازمانی'), 'Commerce block did not render product');
    $assert(str_contains($rendered, 'معماری سیستم‌های ماژولار PHP'), 'LMS block did not render course');
    $assert(str_contains($rendered, 'فرم ارتباط با ما'), 'Forms block did not render form');
    $assert(!str_contains($rendered, '<script>') && !str_contains($rendered, 'onclick='), 'Module block XSS sanitizer failed');

    // 11. Dynamic Content Engine (Custom Content Types & Typed Fields)
    DynamicContentService::defineType($pdo, 'real-estate', [
        'label' => 'ملک',
        'plural_label' => 'املاک',
        'fields' => [
            ['key' => 'area_sqm', 'label' => 'متراژ', 'type' => 'number', 'required' => true],
            ['key' => 'deal_type', 'label' => 'نوع معامله', 'type' => 'select', 'required' => true, 'options' => ['sale', 'rent']],
            ['key' => 'agent_email', 'label' => 'ایمیل مشاور', 'type' => 'email', 'required' => true],
        ],
    ], 1);
    $estate = DynamicContentService::saveEntry($pdo, 'real-estate', [
        'title' => 'آپارتمان مدرن ۱۲۰ متری',
        'locale' => 'fa',
        'status' => 'published',
        'fields' => ['area_sqm' => 120, 'deal_type' => 'sale', 'agent_email' => 'agent@example.com'],
    ], 1, 1);
    $assert(($estate['fields']['area_sqm'] ?? 0) == 120 && ($estate['fields']['deal_type'] ?? '') === 'sale', 'DynamicContentService field hydration failed');
    $assert(count(DynamicContentService::queryEntries($pdo, 'real-estate', ['status' => 'published'], 2)) === 0, 'DynamicContentService tenant isolation failed');

    // 12. Universal Site Blueprints (All 7 Profiles)
    foreach (BlueprintManager::validProfiles() as $bpKey) {
        $res = BlueprintManager::apply($pdo, $bpKey, 'fa', 1);
        $assert($res['blueprint'] === $bpKey, "Blueprint apply failed for {$bpKey}");
    }

    // 13. Edge Page Cache Hit & Tag Invalidation
    $etag = PageCacheService::put(1, 'example.com', '/fa', [], '<html>Cached Home</html>', 'text/html; charset=UTF-8', ['content']);
    $assert($etag !== '', 'PageCache ETag empty');
    $hit = PageCacheService::get(1, 'example.com', '/fa', []);
    $assert(is_array($hit) && $hit['body'] === '<html>Cached Home</html>', 'PageCache HIT failed');
    sleep(1);
    PageCacheService::invalidateTag('content');
    $assert(PageCacheService::get(1, 'example.com', '/fa', []) === null, 'PageCache tag invalidation failed');

    // 14. Comments & Star Reviews Module (Moderation + Average Rating + Honeypot)
    $comments = new \VazinCMS\Modules\Comments\CommentsService(new ModuleDatabase($pdo, 'vazin-comments', 1));
    $c1 = $comments->submit(['target_type' => 'product', 'target_key' => 'pro-cms-01', 'author_name' => 'Nima', 'author_email' => 'nima@example.com', 'rating' => 5, 'body' => 'فوق‌العاده سریع و امن!']);
    $c2 = $comments->submit(['target_type' => 'product', 'target_key' => 'pro-cms-01', 'author_name' => 'Maryam', 'author_email' => 'maryam@example.com', 'rating' => 4, 'body' => 'خیلی بهتر از وردپرس بود.']);
    $assert($comments->approvedForTarget('product', 'pro-cms-01')['count'] === 0, 'Pending comments must not appear before moderation');
    $comments->moderate((int) $c1['id'], 'approved');
    $comments->moderate((int) $c2['id'], 'approved');
    $approvedSummary = $comments->approvedForTarget('product', 'pro-cms-01');
    $assert($approvedSummary['count'] === 2 && $approvedSummary['average_rating'] === 4.5, 'Comments moderation or rating average failed');

    // 15. Privacy-First Cookieless Analytics Module
    $analytics = new \VazinCMS\Modules\Analytics\AnalyticsService(new ModuleDatabase($pdo, 'vazin-analytics', 1));
    $analytics->recordHit('/shop', 'https://google.com/search', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0)', '203.0.113.10');
    $analytics->recordHit('/courses', '', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', '203.0.113.11');
    $analytics->recordHit('/shop', '', 'Googlebot/2.1', '66.249.66.1');
    $stats = $analytics->summary();
    $assert($stats['total_views'] === 2 && $stats['unique_visitors'] === 2 && $stats['devices']['bot'] === 1, 'Analytics bot filtering or visitor count failed');

    // 16. VIP Memberships & Paywall Block
    $memberships = new \VazinCMS\Modules\Memberships\MembershipService(new ModuleDatabase($pdo, 'vazin-memberships', 1));
    $memberships->createPlan(['plan_key' => 'pro-vip', 'title' => 'اشتراک ویژه ماهانه', 'duration_days' => 30, 'price' => 50]);
    $assert(!$memberships->hasActiveAccess('vip@example.com', 'pro-vip'), 'Non-member should not have VIP access');
    $memberships->grantSubscription('pro-vip', 'vip@example.com', 'VIP User');
    $assert($memberships->hasActiveAccess('vip@example.com', 'pro-vip'), 'Granted member should have active VIP access');
    $lockedHtml = BlockEditor::render(BlockEditor::decode(json_encode([
        'schema' => 1,
        'blocks' => [['type' => 'vazin-memberships/paywall', 'data' => ['plan_key' => 'pro-vip', 'teaser' => 'فقط اعضای ویژه', 'vip_content' => 'SECRET_VIP_TEXT']]],
    ], JSON_THROW_ON_ERROR)));
    $assert(str_contains($lockedHtml, 'فقط اعضای ویژه') && !str_contains($lockedHtml, 'SECRET_VIP_TEXT'), 'Anonymous visitor saw locked VIP paywall content');

    // 17. Multi-Theme Bundled Ecosystem Verification
    foreach (['vazin-default', 'vazin-editorial', 'vazin-storefront'] as $themeKey) {
        $tm = ExtensionManifest::fromDirectory($root . '/extensions/themes/' . $themeKey);
        $assert($tm->type() === 'theme', "Theme {$themeKey} manifest invalid");
    }

    echo "V11_MODULAR_SUITE=PASS all_17_pillars_verified\n";
} finally {
    Database::reset();
    $cleanup($tmpDir);
}
