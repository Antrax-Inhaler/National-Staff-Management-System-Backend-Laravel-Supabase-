<?php

namespace App\Services;

use App\Models\Affiliate;
use Illuminate\Support\Str;

class MemberIdGeneratorService
{
    /**
     * Generate a member ID based on affiliate name and member ID
     * Format: 2 letters (from affiliate name) + 8 digits (random + member ID suffix)
     * 
     * Examples:
     * - Member ID 5: "FE12345605" (6 random + 2 digits: "05")
     * - Member ID 99: "FE12345699" (6 random + 2 digits: "99")
     * - Member ID 150: "FE12345150" (5 random + 3 digits: "150")
     * - Member ID 12345678: "FE12345678" (0 random + 8 digits: "12345678")
     *
     * @param int|Affiliate $affiliate Affiliate ID or Affiliate model instance
     * @param int $memberId The member's ID to append
     * @return string Generated member ID (e.g., "FE12345678")
     * @throws \Exception
     */
    public function generate($affiliate, int $memberId): string
    {
        $affiliateName = null;

        if ($affiliate instanceof Affiliate) {
            $affiliateName = $affiliate->name;
        } elseif (is_numeric($affiliate)) {
            $affiliateName = Affiliate::find($affiliate)?->name;
        }

        $prefix = $affiliateName
            ? $this->generatePrefix($affiliateName)
            : $this->fallbackPrefix('member-' . $memberId);

        $memberIdStr = (string) $memberId;
        $length = strlen($memberIdStr);

        if ($length >= 8) {
            return strtoupper($prefix . substr($memberIdStr, -8));
        }

        $randomDigits = $this->generateDigits(8 - $length);

        return strtoupper($prefix . $randomDigits . $memberIdStr);
    }

    protected function fallbackPrefix(string $source): string
    {
        $hash = (int) sprintf('%u', crc32($source));

        $first = chr(65 + ($hash % 26));
        $second = chr(65 + (int)(($hash / 26) % 26));

        return strtoupper($first . $second);
    }

    /**
     * Generate prefix from affiliate name
     * - If name has 2+ words: take first letter of first 2 words
     * - If name has 1 word with 2+ letters: take first 2 letters
     * - If name has 1 letter: repeat it twice
     *
     * @param string $name
     * @return string 2-letter prefix
     */
    protected function generatePrefix(string $name): string
    {
        // Clean and split the name
        $name = trim($name);
        $words = preg_split('/\s+/', $name);

        // Filter out empty strings
        $words = array_filter($words);

        if (count($words) >= 2) {
            // Take first letter of first two words
            // Example: "Fermin Affiliate" -> "FA"
            return substr($words[0], 0, 1) . substr($words[1], 0, 1);
        } elseif (count($words) === 1) {
            $word = $words[0];

            if (strlen($word) >= 2) {
                // Take first 2 letters
                // Example: "Fermin" -> "FE"
                return substr($word, 0, 2);
            } elseif (strlen($word) === 1) {
                // Repeat the single letter
                // Example: "F" -> "FF"
                return $word . $word;
            }
        }

        // Fallback to "XX" if name is empty or invalid
        return 'XX';
    }

    /**
     * Generate random digits
     *
     * @param int $length Number of digits to generate (default: 8)
     * @return string Random digit string
     */
    protected function generateDigits(int $length = 8): string
    {
        $max = (int) str_repeat('9', $length);
        return str_pad(random_int(0, $max), $length, '0', STR_PAD_LEFT);
    }

    /**
     * Generate a unique member ID (checks database for uniqueness across all records)
     * This method is now deprecated - member IDs are unique by design since they include the member's ID
     * 
     * @param int|Affiliate $affiliate
     * @param int $memberId The member's ID
     * @param string $memberModel Full class name of member model (e.g., \App\Models\Member::class)
     * @param string $columnName Column name to check for uniqueness (default: 'member_id')
     * @param int $maxAttempts Maximum attempts to generate unique ID
     * @return string Unique member ID
     * @throws \Exception
     */
    public function generateUnique(
        $affiliate,
        int $memberId,
        string $memberModel,
        string $columnName = 'member_id',
        int $maxAttempts = 100
    ): string {
        // Since we're appending the member ID, it should be unique by design
        // But we keep this check for safety
        $attempts = 0;

        do {
            $generatedId = $this->generate($affiliate, $memberId);

            // Check across ALL records in the table
            $exists = $memberModel::where($columnName, $generatedId)->exists();

            $attempts++;

            if ($attempts >= $maxAttempts) {
                throw new \Exception("Failed to generate unique member ID after {$maxAttempts} attempts");
            }
        } while ($exists);

        return $generatedId;
    }

    /**
     * Generate a globally unique member ID (checks multiple tables)
     * Use this if member IDs need to be unique across different tables
     * This method is now deprecated - member IDs are unique by design since they include the member's ID
     * 
     * @param int|Affiliate $affiliate
     * @param int $memberId The member's ID
     * @param array $modelsToCheck Array of [model => column] to check uniqueness
     *                              Example: [\App\Models\Member::class => 'member_id', \App\Models\OldMember::class => 'id']
     * @param int $maxAttempts Maximum attempts to generate unique ID
     * @return string Globally unique member ID
     * @throws \Exception
     */
    public function generateGloballyUnique(
        $affiliate,
        int $memberId,
        array $modelsToCheck,
        int $maxAttempts = 100
    ): string {
        $attempts = 0;

        do {
            $generatedId = $this->generate($affiliate, $memberId);
            $exists = false;

            // Check each model/table
            foreach ($modelsToCheck as $model => $column) {
                if ($model::where($column, $generatedId)->exists()) {
                    $exists = true;
                    break;
                }
            }

            $attempts++;

            if ($attempts >= $maxAttempts) {
                throw new \Exception("Failed to generate globally unique member ID after {$maxAttempts} attempts");
            }
        } while ($exists);

        return $generatedId;
    }

    /**
     * Validate member ID format
     *
     * @param string $memberId
     * @return bool
     */
    public function validate(string $memberId): bool
    {
        // Check format: 2 letters + 8 digits
        return preg_match('/^[A-Z]{2}\d{8}$/', $memberId) === 1;
    }

    /**
     * Parse concatenated member IDs
     *
     * @param string $concatenated
     * @return array Array of member IDs
     */
    public function parseConcatenated(string $concatenated): array
    {
        $memberIds = [];
        $pattern = '/[A-Z]{2}\d{8}/';

        preg_match_all($pattern, $concatenated, $matches);

        return $matches[0] ?? [];
    }
}
