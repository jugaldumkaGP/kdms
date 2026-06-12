<?php

declare(strict_types=1);

namespace KdmsRegistration;

/**
 * Normalization for PWA registration (title case, dates, optional fields).
 */
final class RegistrationFields
{
    public static function titleCase(string $value, int $maxLen): string
    {
        $value = trim(strip_tags($value));
        if ($value === '') {
            return '';
        }
        $lower = mb_strtolower($value, 'UTF-8');
        $cased = mb_convert_case($lower, MB_CASE_TITLE, 'UTF-8');

        return mb_strlen($cased) > $maxLen ? mb_substr($cased, 0, $maxLen) : $cased;
    }

    public static function sanitizeName(string $value): string
    {
        return self::titleCase($value, 50);
    }

    public static function sanitizeShort(string $value, int $maxLen = 100): string
    {
        return self::titleCase($value, $maxLen);
    }

    public static function sanitizeReferral(string $value): string
    {
        $value = trim(strip_tags($value));

        return strlen($value) > 50 ? substr($value, 0, 50) : $value;
    }

    /** devotee.Devotee_ID_Type is VARCHAR(10); align with staff UI labels (DL, PAN, etc.). */
    public static function sanitizeIdType(string $value): string
    {
        $value = trim(strip_tags($value));
        if ($value === '') {
            return '';
        }

        static $aliases = [
            'aadhaar' => 'Aadhaar',
            'aadhar' => 'Aadhaar',
            'voter id' => 'Voter ID',
            'pan' => 'PAN',
            'pan card' => 'PAN',
            'passport' => 'Passport',
            'dl' => 'DL',
            'driving license' => 'DL',
            'driving licence' => 'DL',
            'other' => 'Other',
            'other gov. id' => 'Other',
            'other government id' => 'Other',
        ];

        $key = mb_strtolower($value, 'UTF-8');
        if (isset($aliases[$key])) {
            return $aliases[$key];
        }

        return strlen($value) <= 10 ? $value : '';
    }

    public static function sanitizeGender(string $value): string
    {
        $value = strtoupper(trim($value));
        if ($value === 'M' || $value === 'F') {
            return $value;
        }
        if (str_starts_with($value, 'M') || $value === 'MALE') {
            return 'M';
        }
        if (str_starts_with($value, 'F') || $value === 'FEMALE') {
            return 'F';
        }

        return '';
    }

    public static function sanitizeEmail(string $value): string
    {
        $value = trim(strip_tags($value));
        if ($value === '') {
            return '';
        }
        if (strlen($value) > 40) {
            $value = substr($value, 0, 40);
        }

        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false ? $value : '';
    }

    public static function sanitizeZip(string $value): string
    {
        $value = trim(strip_tags($value));

        return strlen($value) > 12 ? substr($value, 0, 12) : $value;
    }

    public static function sanitizePhone(string $value): string
    {
        $root = dirname(__DIR__, 3);
        $helper = $root . '/includes/kdms_phone.php';
        if (is_file($helper)) {
            require_once $helper;
            [$normalized, $err] = kdms_normalize_devotee_phone($value);

            return $err === null ? $normalized : '';
        }

        $digits = preg_replace('/\D+/', '', trim($value)) ?? '';

        return strlen($digits) > 10 ? substr($digits, 0, 10) : $digits;
    }

    /**
     * Accept Y-m-d, d-m-Y, d/m/Y, etc. Returns Y-m-d or ''.
     */
    public static function parseDate(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $formats = ['Y-m-d', 'd-m-Y', 'd/m/Y', 'd-m-y', 'd/m/y'];
        foreach ($formats as $fmt) {
            $d = \DateTime::createFromFormat($fmt, $value);
            if ($d instanceof \DateTime) {
                $errors = \DateTime::getLastErrors();
                if (($errors['warning_count'] ?? 0) === 0 && ($errors['error_count'] ?? 0) === 0) {
                    return $d->format('Y-m-d');
                }
            }
        }

        $ts = strtotime($value);
        if ($ts !== false) {
            return date('Y-m-d', $ts);
        }

        return '';
    }

    /** Display DOB as DD-MM-YYYY for the PWA text field. */
    public static function formatDobDisplay(string $isoDate): string
    {
        $isoDate = trim($isoDate);
        if ($isoDate === '') {
            return '';
        }
        $d = \DateTime::createFromFormat('Y-m-d', $isoDate);
        if ($d instanceof \DateTime && $d->format('Y-m-d') === $isoDate) {
            return $d->format('d-m-Y');
        }

        return $isoDate;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{
     *   first: string, last: string, idType: string, idNumber: string,
     *   phone: string, dob: string, gender: string, email: string, referral: string,
     *   address1: string, address2: string, station: string, state: string, zip: string
     * }
     */
    public static function fromRegistrationInput(array $input): array
    {
        $rawIdType = trim(strip_tags((string) ($input['Devotee_ID_Type'] ?? '')));
        $idType = self::sanitizeIdType($rawIdType);
        $idNumber = IdNormalizer::normalize($idType, (string) ($input['Devotee_ID_Number'] ?? ''));

        return [
            'first' => self::sanitizeName((string) ($input['Devotee_First_Name'] ?? '')),
            'last' => self::sanitizeName((string) ($input['Devotee_Last_Name'] ?? '')),
            'idType' => $idType,
            'rawIdType' => $rawIdType,
            'idNumber' => $idNumber,
            'phone' => self::sanitizePhone((string) ($input['Devotee_Cell_Phone_Number'] ?? '')),
            'dob' => self::parseDate((string) ($input['Devotee_DOB'] ?? '')),
            'gender' => self::sanitizeGender((string) ($input['Devotee_Gender'] ?? '')),
            'email' => self::sanitizeEmail((string) ($input['Devotee_Email'] ?? '')),
            'referral' => self::sanitizeReferral((string) ($input['Devotee_Referral'] ?? '')),
            'address1' => self::sanitizeShort((string) ($input['Devotee_Address_1'] ?? ''), 100),
            'address2' => self::sanitizeShort((string) ($input['Devotee_Address_2'] ?? ''), 100),
            'station' => self::sanitizeShort((string) ($input['Devotee_Station'] ?? ''), 50),
            'state' => self::sanitizeShort((string) ($input['Devotee_State'] ?? ''), 25),
            'zip' => self::sanitizeZip((string) ($input['Devotee_Zip'] ?? '')),
        ];
    }

    /**
     * @param array<string, string> $fields
     * @return array<string, mixed>
     */
    public static function toDedupPayload(string $devoteeKey, array $fields, string $eventId, string $status = 'D'): array
    {
        return [
            'Devotee_Key' => $devoteeKey,
            'Devotee_First_Name' => $fields['first'],
            'Devotee_Last_Name' => $fields['last'],
            'Devotee_ID_Type' => $fields['idType'],
            'Devotee_ID_Number' => $fields['idNumber'],
            'Devotee_Cell_Phone_Number' => $fields['phone'],
            'Devotee_DOB' => $fields['dob'],
            'Devotee_Gender' => $fields['gender'],
            'Devotee_Email' => $fields['email'],
            'Devotee_Referral' => $fields['referral'],
            'Devotee_Address_1' => $fields['address1'],
            'Devotee_Address_2' => $fields['address2'],
            'Devotee_Station' => $fields['station'],
            'Devotee_State' => $fields['state'],
            'Devotee_Zip' => strlen($fields['zip']) > 12 ? substr($fields['zip'], 0, 12) : $fields['zip'],
            'Devotee_Type' => 'T',
            'Devotee_Status' => $status,
            'eventId' => $eventId,
        ];
    }
}
