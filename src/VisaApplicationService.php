<?php
declare(strict_types=1);

namespace VazinCMS;

use DateTimeImmutable;
use PDO;
use RuntimeException;

/**
 * Handles the data boundary for a manual eVisa application.
 *
 * The application payload intentionally has no plaintext database columns:
 * identity and passport fields are sealed as one authenticated value, scoped
 * to the immutable order id. Callers must never log the returned payload.
 */
final class VisaApplicationService
{
    public const SCHEMA_VERSION = '1';
    private const RETENTION_DAYS = 365;

    /**
     * @param array<string,mixed> $input
     * @return array{payload:array<string,mixed>,errors:array<string,string>}
     */
    public function validate(array $input): array
    {
        $errors = [];
        $text = function (string $key, string $label, int $min, int $max, bool $required = true) use ($input, &$errors): ?string {
            $value = trim((string) ($input[$key] ?? ''));
            if ($value === '') {
                if ($required) $errors[$key] = $label . ' را وارد کنید.';
                return null;
            }
            if (preg_match('//u', $value) !== 1 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value) === 1) {
                $errors[$key] = $label . ' معتبر نیست.';
                return null;
            }
            $value = (string) preg_replace('/\s+/u', ' ', $value);
            $length = self::unicodeLength($value);
            if ($length < $min || $length > $max) {
                $errors[$key] = $label . ' باید بین ' . $min . ' تا ' . $max . ' نویسه باشد.';
                return null;
            }
            return $value;
        };
        $choice = function (string $key, string $label, array $allowed) use ($input, &$errors): ?string {
            $value = trim((string) ($input[$key] ?? ''));
            if (!in_array($value, $allowed, true)) {
                $errors[$key] = $label . ' را انتخاب کنید.';
                return null;
            }
            return $value;
        };
        $date = function (string $key, string $label) use ($input, &$errors): ?string {
            $value = trim((string) ($input[$key] ?? ''));
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            $warnings = DateTimeImmutable::getLastErrors();
            if ($value === '' || $parsed === false || $parsed->format('Y-m-d') !== $value || (is_array($warnings) && ($warnings['warning_count'] > 0 || $warnings['error_count'] > 0))) {
                $errors[$key] = $label . ' را به‌صورت تاریخ معتبر وارد کنید.';
                return null;
            }
            return $value;
        };

        $givenName = $text('given_name', 'نام مطابق گذرنامه', 1, 100);
        $familyName = $text('family_name', 'نام خانوادگی مطابق گذرنامه', 1, 100);
        $birthDate = $date('birth_date', 'تاریخ تولد');
        $gender = $choice('gender', 'جنسیت', ['female', 'male', 'other']);
        $birthCountry = $text('birth_country', 'کشور محل تولد', 2, 100);
        $birthCity = $text('birth_city', 'شهر محل تولد', 2, 100);
        $nationality = $text('nationality', 'تابعیت', 2, 100);
        $secondNationality = $text('second_nationality', 'تابعیت دوم', 2, 100, false);

        $passportNumber = strtoupper((string) ($input['passport_number'] ?? ''));
        $passportNumber = trim($passportNumber);
        if (preg_match('/^[A-Z0-9][A-Z0-9 -]{4,29}$/', $passportNumber) !== 1) {
            $errors['passport_number'] = 'شماره گذرنامه معتبر نیست.';
        }
        $passportIssuedAt = $date('passport_issued_at', 'تاریخ صدور گذرنامه');
        $passportExpiresAt = $date('passport_expires_at', 'تاریخ انقضای گذرنامه');
        $passportIssuingCountry = $text('passport_issuing_country', 'کشور صادرکننده گذرنامه', 2, 100);
        $passportIssuingAuthority = $text('passport_issuing_authority', 'مرجع صادرکننده گذرنامه', 2, 190);

        $residenceCountry = $text('residence_country', 'کشور محل اقامت', 2, 100);
        $residenceCity = $text('residence_city', 'شهر محل اقامت', 2, 100);
        $residenceAddress = $text('residence_address', 'نشانی محل اقامت', 5, 500);
        $email = trim((string) ($input['email'] ?? ''));
        if ($email === '' || self::unicodeLength($email) > 190 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'ایمیل معتبر را وارد کنید.';
        }
        $phone = trim((string) ($input['phone'] ?? ''));
        if (self::unicodeLength($phone) < 6 || self::unicodeLength($phone) > 64 || preg_match('/^[\p{N}+().\-\s]+$/u', $phone) !== 1) {
            $errors['phone'] = 'شماره تماس معتبر را وارد کنید.';
        }

        $destination = $text('destination', 'کشور مقصد', 2, 100);
        $visaType = $choice('visa_type', 'نوع ویزا', ['tourism', 'business', 'transit', 'private', 'study', 'other']);
        $entryCount = $choice('entry_count', 'تعداد ورود', ['single', 'double', 'multiple']);
        $arrivalDate = $date('arrival_date', 'تاریخ ورود');
        $departureDate = $date('departure_date', 'تاریخ خروج');
        $accommodationName = $text('accommodation_name', 'نام محل اقامت', 2, 190);
        $accommodationAddress = $text('accommodation_address', 'نشانی محل اقامت در مقصد', 5, 500);

        $employmentStatus = $choice('employment_status', 'وضعیت شغلی', ['employed', 'self_employed', 'student', 'retired', 'unemployed']);
        $employerName = $text('employer_name', 'نام محل کار یا تحصیل', 2, 190, $employmentStatus !== null && in_array($employmentStatus, ['employed', 'self_employed', 'student'], true));
        $employerAddress = $text('employer_address', 'نشانی محل کار یا تحصیل', 5, 500, $employmentStatus !== null && in_array($employmentStatus, ['employed', 'self_employed', 'student'], true));
        $travelPurpose = $text('travel_purpose', 'هدف سفر', 5, 1000);
        $previousVisa = $choice('previous_visa', 'سابقه سفر یا ویزا', ['yes', 'no']);
        $previousVisaDetails = $text('previous_visa_details', 'توضیح سابقه سفر یا ویزا', 5, 1000, $previousVisa === 'yes');
        $emergencyContactName = $text('emergency_contact_name', 'نام شخص تماس اضطراری', 2, 190);
        $emergencyContactRelation = $text('emergency_contact_relation', 'نسبت شخص تماس اضطراری', 2, 100);
        $emergencyContactPhone = trim((string) ($input['emergency_contact_phone'] ?? ''));
        if (self::unicodeLength($emergencyContactPhone) < 6 || self::unicodeLength($emergencyContactPhone) > 64 || preg_match('/^[\p{N}+().\-\s]+$/u', $emergencyContactPhone) !== 1) {
            $errors['emergency_contact_phone'] = 'شماره تماس اضطراری معتبر نیست.';
        }
        $additionalInformation = $text('additional_information', 'توضیحات تکمیلی', 0, 1000, false);

        if (($input['accuracy_consent'] ?? '') !== '1') $errors['accuracy_consent'] = 'تأیید صحت اطلاعات الزامی است.';
        if (($input['privacy_consent'] ?? '') !== '1') $errors['privacy_consent'] = 'رضایت برای بررسی امن پرونده الزامی است.';

        $today = gmdate('Y-m-d');
        if ($birthDate !== null && $birthDate >= $today) $errors['birth_date'] = 'تاریخ تولد باید پیش از امروز باشد.';
        if ($passportIssuedAt !== null && $passportIssuedAt > $today) $errors['passport_issued_at'] = 'تاریخ صدور گذرنامه نمی‌تواند در آینده باشد.';
        if ($passportIssuedAt !== null && $passportExpiresAt !== null && $passportExpiresAt <= $passportIssuedAt) $errors['passport_expires_at'] = 'تاریخ انقضای گذرنامه باید پس از تاریخ صدور باشد.';
        if ($arrivalDate !== null && $arrivalDate < $today) $errors['arrival_date'] = 'تاریخ ورود نمی‌تواند در گذشته باشد.';
        if ($arrivalDate !== null && $departureDate !== null && $departureDate < $arrivalDate) $errors['departure_date'] = 'تاریخ خروج باید هم‌زمان یا پس از تاریخ ورود باشد.';
        if ($passportExpiresAt !== null && $departureDate !== null && $passportExpiresAt <= $departureDate) $errors['passport_expires_at'] = 'گذرنامه باید پس از پایان سفر معتبر بماند.';

        if ($errors !== []) return ['payload' => [], 'errors' => $errors];

        return ['payload' => [
            'schema_version' => self::SCHEMA_VERSION,
            'identity' => [
                'given_name' => $givenName,
                'family_name' => $familyName,
                'birth_date' => $birthDate,
                'gender' => $gender,
                'birth_country' => $birthCountry,
                'birth_city' => $birthCity,
                'nationality' => $nationality,
                'second_nationality' => $secondNationality,
            ],
            'passport' => [
                'number' => $passportNumber,
                'issued_at' => $passportIssuedAt,
                'expires_at' => $passportExpiresAt,
                'issuing_country' => $passportIssuingCountry,
                'issuing_authority' => $passportIssuingAuthority,
            ],
            'residence_contact' => [
                'country' => $residenceCountry,
                'city' => $residenceCity,
                'address' => $residenceAddress,
                'email' => $email,
                'phone' => $phone,
            ],
            'itinerary' => [
                'destination' => $destination,
                'visa_type' => $visaType,
                'entry_count' => $entryCount,
                'arrival_date' => $arrivalDate,
                'departure_date' => $departureDate,
                'accommodation_name' => $accommodationName,
                'accommodation_address' => $accommodationAddress,
            ],
            'work_travel' => [
                'employment_status' => $employmentStatus,
                'employer_name' => $employerName,
                'employer_address' => $employerAddress,
                'travel_purpose' => $travelPurpose,
                'previous_visa' => $previousVisa,
                'previous_visa_details' => $previousVisaDetails,
                'additional_information' => $additionalInformation,
            ],
            'emergency_contact' => [
                'name' => $emergencyContactName,
                'relation' => $emergencyContactRelation,
                'phone' => $emergencyContactPhone,
            ],
            'consent' => [
                'accuracy' => true,
                'privacy' => true,
                'version' => '2026-08',
                'captured_at' => gmdate('c'),
            ],
        ], 'errors' => []];
    }

    /** @param array{id:int|string} $order @param array<string,mixed> $payload */
    public function save(array $order, array $payload): int
    {
        $orderId = (int) ($order['id'] ?? 0);
        if ($orderId < 1) throw new RuntimeException('شناسه پرونده معتبر نیست.');
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $sealed = SecretStore::seal($encoded, $this->context($orderId));
        $pdo = Database::connection();
        $existing = $this->hasSubmission($orderId, $pdo);
        $retentionUntil = gmdate('Y-m-d H:i:s', time() + self::RETENTION_DAYS * 86400);

        $pdo->beginTransaction();
        try {
            $statement = $pdo->prepare(
                'INSERT INTO visa_application_intakes '
                . '(order_id,schema_version,encrypted_payload,consent_at,retention_until,completed_at,created_at,updated_at) '
                . 'VALUES(:order_id,:schema_version,:encrypted_payload,CURRENT_TIMESTAMP,:retention_until,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP) '
                . 'ON CONFLICT(order_id) DO UPDATE SET '
                . 'schema_version=excluded.schema_version,encrypted_payload=excluded.encrypted_payload,consent_at=CURRENT_TIMESTAMP,'
                . 'retention_until=excluded.retention_until,completed_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP'
            );
            $statement->execute([
                'order_id' => $orderId,
                'schema_version' => self::SCHEMA_VERSION,
                'encrypted_payload' => $sealed,
                'retention_until' => $retentionUntil,
            ]);
            $eventParameters = [
                'order_id' => $orderId,
                'event_type' => $existing ? 'application_resubmitted' : 'application_submitted',
                'visibility' => 'customer',
            ];
            $eventSql = 'INSERT INTO visa_case_events(order_id,event_type,visibility) VALUES(:order_id,:event_type,:visibility)';
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
                $event = $pdo->prepare($eventSql . ' RETURNING id');
                $event->execute($eventParameters);
                $eventId = (int)$event->fetchColumn();
            } else {
                $event = $pdo->prepare($eventSql);
                $event->execute($eventParameters);
                $eventId = (int)$pdo->lastInsertId();
            }
            $pdo->commit();
            return $eventId;
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    public function hasSubmission(int $orderId, ?PDO $pdo = null): bool
    {
        $statement = ($pdo ?? Database::connection())->prepare(
            'SELECT 1 FROM visa_application_intakes WHERE order_id=:order_id LIMIT 1'
        );
        $statement->execute(['order_id' => $orderId]);
        return (bool) $statement->fetchColumn();
    }

    /**
     * Opens the sealed intake only for an already-authorized operator surface.
     * The caller must not persist, log, or pass the returned payload to a
     * public view.  The encrypted blob itself is deliberately never returned.
     *
     * @return array{state:'missing'|'expired'|'unavailable'|'available',metadata?:array<string,string>,payload?:array<string,mixed>}
     */
    public function forOperator(int $orderId): array
    {
        if ($orderId < 1) return ['state' => 'missing'];

        try {
            $statement = Database::connection()->prepare(
                'SELECT schema_version,encrypted_payload,consent_at,retention_until,completed_at '
                . 'FROM visa_application_intakes WHERE order_id=:order_id LIMIT 1'
            );
            $statement->execute(['order_id' => $orderId]);
            $row = $statement->fetch();
            if (!is_array($row)) return ['state' => 'missing'];

            $metadata = [
                'schema_version' => (string) ($row['schema_version'] ?? ''),
                'consent_at' => (string) ($row['consent_at'] ?? ''),
                'retention_until' => (string) ($row['retention_until'] ?? ''),
                'completed_at' => (string) ($row['completed_at'] ?? ''),
            ];
            if ($metadata['retention_until'] !== '' && $metadata['retention_until'] < gmdate('Y-m-d H:i:s')) {
                return ['state' => 'expired', 'metadata' => $metadata];
            }

            $opened = SecretStore::open((string) $row['encrypted_payload'], $this->context($orderId));
            $payload = json_decode($opened, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($payload) || (string) ($payload['schema_version'] ?? '') !== self::SCHEMA_VERSION) {
                throw new RuntimeException('Unsupported protected application payload.');
            }
            return ['state' => 'available', 'metadata' => $metadata, 'payload' => $payload];
        } catch (\Throwable $error) {
            // Do not include an order id, cipher text, or any applicant data in
            // server logs. The admin page has a generic recovery message.
            error_log('[VazinCMS] operator visa application load failed type=' . $error::class);
            return ['state' => 'unavailable'];
        }
    }

    private function context(int $orderId): string
    {
        return 'visa.application.' . $orderId;
    }

    private static function unicodeLength(string $value): int
    {
        $count = preg_match_all('/./u', $value, $matches);
        return $count === false ? 0 : $count;
    }
}
