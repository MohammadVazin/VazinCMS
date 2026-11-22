<?php
declare(strict_types=1);

namespace VazinCMS\Modules\Forms;

use InvalidArgumentException;
use VazinCMS\{ExtensionRuntime, ModuleDatabase};

final class FormsModuleService
{
    private const FIELD_TYPES = ['text', 'email', 'phone', 'textarea', 'number', 'select', 'checkbox'];
    private bool $ready = false;

    public function __construct(private ModuleDatabase $db)
    {
    }

    public function ensureSchema(): void
    {
        if ($this->ready) {
            return;
        }
        $this->db->ensureTable('forms', [
            'form_key'        => 'string:80',
            'title'           => 'string:200',
            'submit_label'    => 'string:80:default=ارسال',
            'success_message' => 'string:300',
            'fields_json'     => 'json',
            'status'          => 'string:20:default=active',
        ], [['form_key']]);

        $this->db->ensureTable('submissions', [
            'form_id'      => 'bigint',
            'form_key'     => 'string:80',
            'payload_json' => 'json',
            'ip_hash'      => 'string:64',
            'status'       => 'string:20:default=unread',
        ]);

        $this->ready = true;
    }

    /**
     * @param list<array<string,mixed>> $fields
     * @return array<string,mixed>
     */
    public function saveForm(string $formKey, string $title, array $fields, string $successMessage = 'پیام شما با موفقیت ثبت شد.'): array
    {
        $this->ensureSchema();
        $formKey = strtolower(trim($formKey));
        if (preg_match('/^[a-z0-9][a-z0-9-]{1,78}$/', $formKey) !== 1 || mb_strlen(trim($title)) < 2) {
            throw new InvalidArgumentException('شناسه یا عنوان فرم معتبر نیست.');
        }
        $cleanFields = [];
        foreach ($fields as $f) {
            if (!is_array($f)) continue;
            $k = strtolower(trim((string) ($f['key'] ?? '')));
            $t = strtolower(trim((string) ($f['type'] ?? 'text')));
            if (preg_match('/^[a-z][a-z0-9_]{1,50}$/', $k) !== 1 || !in_array($t, self::FIELD_TYPES, true)) {
                throw new InvalidArgumentException('تعریف فیلد فرم معتبر نیست: ' . $k);
            }
            $cleanFields[] = [
                'key' => $k,
                'label' => mb_substr(trim((string) ($f['label'] ?? $k)), 0, 120),
                'type' => $t,
                'required' => !empty($f['required']),
                'options' => is_array($f['options'] ?? null) ? array_values(array_map('strval', $f['options'])) : [],
            ];
        }
        $existing = $this->db->findOneBy('forms', ['form_key' => $formKey]);
        if ($existing !== null) {
            $this->db->update('forms', (int) $existing['id'], [
                'title' => trim($title),
                'success_message' => mb_substr(trim($successMessage), 0, 300),
                'fields_json' => $cleanFields,
            ]);
            return $this->db->find('forms', (int) $existing['id']) ?? [];
        }
        $id = $this->db->insert('forms', [
            'form_key' => $formKey,
            'title' => trim($title),
            'submit_label' => 'ارسال پیام',
            'success_message' => mb_substr(trim($successMessage), 0, 300),
            'fields_json' => $cleanFields,
            'status' => 'active',
        ]);
        return $this->db->find('forms', $id) ?? ['id' => $id];
    }

    /**
     * @param array<string,mixed> $input
     * @return array{submission_id:int,message:string}
     */
    public function submit(string $formKey, array $input, string $clientIp = ''): array
    {
        $this->ensureSchema();
        // Anti-spam Honeypot trap
        if (!empty($input['_hp_website'])) {
            throw new InvalidArgumentException('ارسال اسپم شناسایی و مسدود شد.');
        }
        $form = $this->db->findOneBy('forms', ['form_key' => strtolower(trim($formKey)), 'status' => 'active']);
        if ($form === null) {
            throw new InvalidArgumentException('فرم موردنظر فعال نیست.');
        }
        $fields = json_decode((string) ($form['fields_json'] ?? '[]'), true) ?: [];
        $clean = [];
        foreach ($fields as $f) {
            $k = (string) $f['key'];
            $val = $input[$k] ?? null;
            $str = is_scalar($val) ? trim((string) $val) : '';
            if (!empty($f['required']) && $str === '') {
                throw new InvalidArgumentException('تکمیل فیلد «' . $f['label'] . '» الزامی است.');
            }
            if ($str === '') {
                $clean[$k] = '';
                continue;
            }
            if ($f['type'] === 'email' && !filter_var($str, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('ایمیل واردشده معتبر نیست.');
            }
            if ($f['type'] === 'number' && !is_numeric($str)) {
                throw new InvalidArgumentException('مقدار فیلد «' . $f['label'] . '» باید عددی باشد.');
            }
            $clean[$k] = mb_substr($str, 0, 4000);
        }

        $subId = $this->db->insert('submissions', [
            'form_id' => (int) $form['id'],
            'form_key' => (string) $form['form_key'],
            'payload_json' => $clean,
            'ip_hash' => $clientIp !== '' ? hash('sha256', $clientIp) : '',
            'status' => 'unread',
        ]);

        ExtensionRuntime::emit('forms.submitted', [
            'submission_id' => $subId,
            'form_key' => $form['form_key'],
        ]);

        return [
            'submission_id' => $subId,
            'message' => (string) ($form['success_message'] ?: 'پیام شما ثبت شد.'),
        ];
    }

    /**
     * @return ?array<string,mixed>
     */
    public function getForm(string $formKey): ?array
    {
        $this->ensureSchema();
        return $this->db->findOneBy('forms', ['form_key' => strtolower(trim($formKey)), 'status' => 'active']);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listSubmissions(int $limit = 50): array
    {
        $this->ensureSchema();
        return $this->db->where('submissions', [], 'id DESC', $limit);
    }
}
