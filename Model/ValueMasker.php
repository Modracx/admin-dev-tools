<?php
declare(strict_types=1);

namespace Modracx\AdminDevTools\Model;

/**
 * Decides whether a stored value may be shown, and shortens the ones that may.
 *
 * Shared by the config inspector and the activity log so both apply the same rule —
 * a credential must not become visible just because it was reached through a different
 * panel.
 */
class ValueMasker
{
    public const MASK = '••••••••';

    /** Field or path fragments whose values are never shown. */
    private const SENSITIVE_PATTERN = '~(pass|secret|key|token|salt|private|credential|licen[cs]e|signature|cipher)~i';

    /** Magento's encrypted values look like "0:3:base64…". */
    private const ENCRYPTED_PATTERN = '~^\d+:\d+:~';

    private const MAX_LENGTH = 200;

    public function isSensitive(string $name, mixed $value = null): bool
    {
        if (preg_match(self::SENSITIVE_PATTERN, $name)) {
            return true;
        }

        return is_string($value) && $value !== '' && (bool) preg_match(self::ENCRYPTED_PATTERN, $value);
    }

    /**
     * Render a value for display: masked if sensitive, flattened and truncated otherwise.
     */
    public function present(mixed $value, bool $masked, int $maxLength = self::MAX_LENGTH): string
    {
        if ($masked) {
            return self::MASK;
        }

        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value) || is_object($value)) {
            $value = json_encode($value);
            if ($value === false) {
                return '(unserialisable)';
            }
        }

        $value = (string) $value;

        return mb_strlen($value) > $maxLength ? mb_substr($value, 0, $maxLength) . '…' : $value;
    }

    /**
     * Convenience: decide and render in one step.
     */
    public function mask(string $name, mixed $value, int $maxLength = self::MAX_LENGTH): string
    {
        return $this->present($value, $this->isSensitive($name, $value), $maxLength);
    }
}
