<?php
declare(strict_types=1);

namespace EducationalCurriculum\Sync;

/**
 * Changes that applying a release makes, and the errors that block it.
 *
 * Its JSON is the report a person approves; the sha256 of that JSON is its fingerprint.
 */
class Plan
{
    const ERROR_UNKNOWN_IDENTIFIER = 'unknown_identifier';
    const ERROR_CLASS_MISMATCH = 'class_mismatch';
    const ERROR_UNRETIRED = 'unretired';
    const ERROR_DUPLICATE_IDENTIFIER = 'duplicate_identifier';
    const ERROR_MISSING_IDENTIFIER = 'missing_identifier';
    const ERROR_UNKNOWN_LINK = 'unknown_link';
    const ERROR_VOCABULARY_TERM_REMOVED = 'vocabulary_term_removed';
    const ERROR_VOCABULARY_TERM_MISSING = 'vocabulary_term_missing';

    private $version;
    private $summary;
    private $new;
    private $modified;
    private $retired;
    private $vocabulary;
    private $errors;

    public function __construct(
        string $version,
        array $summary,
        array $new,
        array $modified,
        array $retired,
        array $vocabulary,
        array $errors
    ) {
        $this->version = $version;
        $this->summary = $summary;
        $this->new = $new;
        $this->modified = $modified;
        $this->retired = $retired;
        $this->vocabulary = $vocabulary;
        $this->errors = $errors;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    /**
     * Counts of new, modified, retired and unchanged items by resource class.
     */
    public function getSummary(): array
    {
        return $this->summary;
    }

    /**
     * Items to create: identifier, class, retired and all their values.
     */
    public function getNew(): array
    {
        return $this->new;
    }

    /**
     * Items whose values change: identifier, item id, class and the changes by property.
     */
    public function getModified(): array
    {
        return $this->modified;
    }

    /**
     * Items retired in this release, in the same shape as the modified ones.
     */
    public function getRetired(): array
    {
        return $this->retired;
    }

    /**
     * Terms of the cur vocabulary that the release adds and removes.
     */
    public function getVocabulary(): array
    {
        return $this->vocabulary;
    }

    /**
     * Errors that block applying the plan: code, identifier, item id and detail.
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    /**
     * Whether applying the plan would change nothing.
     */
    public function isEmpty(): bool
    {
        return $this->new === [] && $this->modified === [] && $this->retired === []
            && $this->vocabulary['added'] === [] && $this->vocabulary['removed'] === [];
    }

    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'summary' => $this->summary,
            'new' => $this->new,
            'modified' => $this->modified,
            'retired' => $this->retired,
            'vocabulary' => $this->vocabulary,
            'errors' => $this->errors,
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public function getFingerprint(): string
    {
        return hash('sha256', $this->toJson());
    }
}
