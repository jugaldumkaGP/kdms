<?php

declare(strict_types=1);

namespace KdmsRegistration;

final class IdNormalizer
{
    public const CONFIDENCE_HIGH = 0.70;
    public const CONFIDENCE_MEDIUM = 0.40;

    public static function normalize(string $idType, string $idNumber): string
    {
        $type = trim($idType);
        $value = trim($idNumber);

        switch ($type) {
            case 'Aadhaar':
                $value = preg_replace('/[\s\-]+/', '', $value) ?? $value;
                if (preg_match('/^\d{12}$/', $value)) {
                    return $value;
                }

                return $value;

            case 'PAN Card':
            case 'PAN':
                $value = strtoupper(preg_replace('/\s+/', '', $value) ?? $value);

                return $value;

            case 'Voter ID':
                $value = strtoupper(preg_replace('/\s+/', '', $value) ?? $value);

                return $value;

            case 'Passport':
                $value = strtoupper(preg_replace('/\s+/', '', $value) ?? $value);

                return $value;

            case 'DL':
            case 'Driving License':
                $value = strtoupper(preg_replace('/\s+/', '', $value) ?? $value);

                return $value;

            default:
                return strtoupper(trim($value));
        }
    }
}
