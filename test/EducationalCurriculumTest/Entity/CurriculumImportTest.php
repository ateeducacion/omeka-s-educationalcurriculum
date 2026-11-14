<?php

declare(strict_types=1);

namespace EducationalCurriculumTest\Entity;

use DateTime;
use EducationalCurriculum\Entity\CurriculumImport;
use InvalidArgumentException;
use Omeka\Entity\Job;
use Omeka\Entity\User;
use PHPUnit\Framework\TestCase;

class CurriculumImportTest extends TestCase
{
    public function testStoresImportData(): void
    {
        $import = new CurriculumImport();
        $analyzeJob = new Job();
        $applyJob = new Job();
        $owner = new User();

        $import->setVersion('1.1.2');
        $import->setStatus(CurriculumImport::STATUS_READY);
        $import->setReportHash(str_repeat('a', 64));
        $import->setAnalyzeJob($analyzeJob);
        $import->setApplyJob($applyJob);
        $import->setOwner($owner);

        $this->assertNull($import->getId());
        $this->assertSame('1.1.2', $import->getVersion());
        $this->assertSame('ready', $import->getStatus());
        $this->assertSame(str_repeat('a', 64), $import->getReportHash());
        $this->assertSame($analyzeJob, $import->getAnalyzeJob());
        $this->assertSame($applyJob, $import->getApplyJob());
        $this->assertSame($owner, $import->getOwner());
    }

    public function testAcceptsEveryStatusOfTheFlow(): void
    {
        $import = new CurriculumImport();
        $expected = ['analyzing', 'ready', 'blocked', 'stale', 'applying', 'interrupted', 'applied', 'discarded'];

        $this->assertSame($expected, CurriculumImport::STATUSES);
        foreach ($expected as $status) {
            $import->setStatus($status);
            $this->assertSame($status, $import->getStatus());
        }
    }

    public function testRejectsUnknownStatus(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new CurriculumImport())->setStatus('done');
    }

    public function testLifecycleCallbacksSetDates(): void
    {
        $import = new CurriculumImport();
        $this->assertNull($import->getCreated());
        $this->assertNull($import->getModified());

        $import->prePersist();
        $this->assertInstanceOf(DateTime::class, $import->getCreated());
        $this->assertNull($import->getModified());

        $import->preUpdate();
        $this->assertInstanceOf(DateTime::class, $import->getModified());
    }
}
