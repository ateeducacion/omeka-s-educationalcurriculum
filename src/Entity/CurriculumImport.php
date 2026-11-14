<?php
declare(strict_types=1);

namespace EducationalCurriculum\Entity;

use DateTime;
use InvalidArgumentException;
use Omeka\Entity\AbstractEntity;
use Omeka\Entity\Job;
use Omeka\Entity\User;

/**
 * One upload of a curriculum release: its files live in files/educationalcurriculum/<id>/.
 *
 * @Entity
 * @HasLifecycleCallbacks
 */
class CurriculumImport extends AbstractEntity
{
    const STATUS_ANALYZING = 'analyzing'; // @translate
    const STATUS_READY = 'ready'; // @translate
    const STATUS_BLOCKED = 'blocked'; // @translate
    const STATUS_STALE = 'stale'; // @translate
    const STATUS_APPLYING = 'applying'; // @translate
    const STATUS_INTERRUPTED = 'interrupted'; // @translate
    const STATUS_APPLIED = 'applied'; // @translate
    const STATUS_DISCARDED = 'discarded'; // @translate

    const STATUSES = [
        self::STATUS_ANALYZING,
        self::STATUS_READY,
        self::STATUS_BLOCKED,
        self::STATUS_STALE,
        self::STATUS_APPLYING,
        self::STATUS_INTERRUPTED,
        self::STATUS_APPLIED,
        self::STATUS_DISCARDED,
    ];

    /**
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    protected $id;

    /**
     * Release version, from corpus.version in curriculo.json.
     *
     * @Column(type="string", length=64)
     */
    protected $version;

    /**
     * @Column(type="string", length=16)
     */
    protected $status;

    /**
     * Fingerprint of the approved report: sha256 of informe.json.
     *
     * @Column(type="string", length=64, nullable=true)
     */
    protected $reportHash;

    /**
     * @ManyToOne(targetEntity="Omeka\Entity\Job")
     * @JoinColumn(nullable=true, onDelete="SET NULL")
     */
    protected $analyzeJob;

    /**
     * @ManyToOne(targetEntity="Omeka\Entity\Job")
     * @JoinColumn(nullable=true, onDelete="SET NULL")
     */
    protected $applyJob;

    /**
     * @ManyToOne(targetEntity="Omeka\Entity\User")
     * @JoinColumn(nullable=true, onDelete="SET NULL")
     */
    protected $owner;

    /**
     * @Column(type="datetime")
     */
    protected $created;

    /**
     * @Column(type="datetime", nullable=true)
     */
    protected $modified;

    public function getId()
    {
        return $this->id;
    }

    public function setVersion(string $version): void
    {
        $this->version = $version;
    }

    public function getVersion(): ?string
    {
        return $this->version;
    }

    public function setStatus(string $status): void
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException(sprintf('Invalid curriculum import status "%s".', $status));
        }
        $this->status = $status;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setReportHash(?string $reportHash): void
    {
        $this->reportHash = $reportHash;
    }

    public function getReportHash(): ?string
    {
        return $this->reportHash;
    }

    public function setAnalyzeJob(?Job $analyzeJob): void
    {
        $this->analyzeJob = $analyzeJob;
    }

    public function getAnalyzeJob(): ?Job
    {
        return $this->analyzeJob;
    }

    public function setApplyJob(?Job $applyJob): void
    {
        $this->applyJob = $applyJob;
    }

    public function getApplyJob(): ?Job
    {
        return $this->applyJob;
    }

    public function setOwner(?User $owner): void
    {
        $this->owner = $owner;
    }

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    public function getCreated(): ?DateTime
    {
        return $this->created;
    }

    public function getModified(): ?DateTime
    {
        return $this->modified;
    }

    /**
     * @PrePersist
     */
    public function prePersist(): void
    {
        $this->created = new DateTime('now');
    }

    /**
     * @PreUpdate
     */
    public function preUpdate(): void
    {
        $this->modified = new DateTime('now');
    }
}
